<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseImportProjection;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const IMPORT_PASSWORD = 'import-owner-secret-sentinel';
const IMPORT_FILE = '/home/orbit/imports/shop.sql.gz';

beforeEach(function (): void {
    $node = static fn (string $name, string $ip): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
    ]));
    $this->gateway = $node('gateway', '10.44.0.1');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->dbNode = $node('db', '10.44.0.12');
    $this->peer = $node('app', '10.44.0.13');
    $this->peer->accessibleNodes()->attach($this->gateway->id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'beast-mysql', 'driver' => 'mysql', 'node_id' => $this->dbNode->id, 'tag' => '8.4', 'port' => 3307, 'status' => 'active',
        'root_password' => 'import-root-secret-sentinel',
    ]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->dbNode->id, 'name' => 'beast-mysql', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld']], 'desired_state' => 'running', 'status' => 'active', 'keep_alive' => true]);
    $this->server->update(['process_id' => $process->id]);
    $this->connection = DatabaseConnection::query()->create([
        'slug' => 'shop', 'driver' => 'mysql', 'node_id' => $this->dbNode->id, 'host' => '10.44.0.12', 'port' => 3307,
        'database' => 'shop', 'username' => 'shop_owner', 'password' => IMPORT_PASSWORD, 'database_server_id' => $this->server->id,
    ]);
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

it('records the import on the server Node with the password only in the protected intent', function (): void {
    $response = $this->postJson('/api/v1/database-connections/shop/import', ['file' => IMPORT_FILE])
        ->assertAccepted()->assertJsonPath('data.kind', 'database.import');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
    $command = $intent->payload['commands']['database-apply'];
    $argv = $command['input']['argv'];

    expect($operation->node_id)->toBe($this->dbNode->id)
        ->and($operation->input['file'])->toBe(IMPORT_FILE)
        ->and($operation->state['swamp_start']['workflow'])->toBe('operation-database-import')
        ->and($command['protected'])->toBe(['password'])
        ->and(array_slice($argv, 0, 4))->toBe(['timeout', '3600', 'bash', '-c'])
        ->and(array_slice($argv, 5))->toBe(['bash', IMPORT_FILE, 'orbit-process-'.$this->server->process_id.'-beast-mysql', 'mysql:8.4', 'mysql', 'shop_owner', 'shop'])
        ->and(json_encode($argv))->not->toContain(IMPORT_PASSWORD)
        ->and(json_encode($operation->input))->not->toContain(IMPORT_PASSWORD)
        ->and(json_encode($operation->result))->not->toContain(IMPORT_PASSWORD)
        ->and($response->getContent())->not->toContain(IMPORT_PASSWORD);

    // The workflow input holds only vault key names; the value goes Gateway → vault as the password line.
    app()->instance(SwampSshExecutionInput::class, new class
    {
        /** @return array<string, mixed> */
        public function forNode(Node $node): array
        {
            return ['target' => ['node_id' => $node->id, 'user' => $node->user]];
        }
    });
    $copies = [];
    app()->instance(SwampCommandStep::class, new class($copies)
    {
        /** @param array<int, array{string, string, string}> $copies */
        public function __construct(private array &$copies) {}

        /** @return array{vault: string, key: string} */
        public function executionCopy(string $vault, string $key, string $value): array
        {
            $this->copies[] = [$vault, $key, $value];

            return ['vault' => $vault, 'key' => $key];
        }
    });
    $input = app(DatabaseImportProjection::class)->input($operation->refresh());
    expect($copies)->toBe([['orbit-control-inputs', 'database-import-intent-'.$intent->id, IMPORT_PASSWORD."\n"]])
        ->and($input['execution_secrets'])->toBe(['import' => ['vault' => 'orbit-control-inputs', 'key' => 'database-import-intent-'.$intent->id]])
        ->and($input['intent']['ssh_execution']['target'])->toBe(['node_id' => $this->dbNode->id, 'user' => 'orbit'])
        ->and(json_encode($input))->not->toContain(IMPORT_PASSWORD);
});

it('refuses a path outside the rule before recording anything', function (string $file): void {
    $this->postJson('/api/v1/database-connections/shop/import', ['file' => $file])
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.import_path_invalid');
    expect(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(0);
})->with([
    'relative' => ['imports/shop.sql'],
    'dot dot' => ['/home/orbit/../root/shop.sql'],
    'dot' => ['/home/orbit/./shop.sql'],
    'other suffix' => ['/home/orbit/shop.dump'],
    'shell character' => ['/home/orbit/shop$(id).sql'],
    'space' => ['/home/orbit/my shop.sql'],
]);

it('refuses a database that is not on an active MySQL or PostgreSQL server', function (string $case): void {
    match ($case) {
        'external' => $this->connection->update(['database_server_id' => null]),
        'inactive' => $this->server->update(['status' => 'failed']),
        'sqlite' => $this->connection->update(['driver' => 'sqlite', 'path' => '/srv/shop.sqlite']),
    };
    $this->postJson('/api/v1/database-connections/shop/import', ['file' => IMPORT_FILE])
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.import_unsupported');
    expect(Operation::query()->count())->toBe(0);
})->with(['external', 'inactive', 'sqlite']);

it('refuses a caller without Gateway authority, as every database command does', function (): void {
    $this->peer->accessibleNodes()->detach($this->gateway->id);
    $this->peer->accessibleNodes()->attach($this->dbNode->id);
    $this->withServerVariables(['REMOTE_ADDR' => $this->peer->wireguard_ip])
        ->postJson('/api/v1/database-connections/shop/import', ['file' => IMPORT_FILE])
        ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(0);
});
