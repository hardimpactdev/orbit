<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerCommands;
use App\Domain\DatabaseServers\DatabaseServerCreateProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Processes\ProcessTargetResolver;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
});

function valkeyImageUpdateServer(string $driver = 'redis', string $desired = 'running'): DatabaseServer
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'database',
        'runtime' => 'docker', 'working_directory' => '/data',
        'runtime_config' => [
            'image' => $driver === 'redis' ? 'valkey/valkey:8.1' : 'mysql:8.4',
            'command' => $driver === 'redis'
                ? ['valkey-server', '--appendonly', 'yes', '--dir', '/data', '--aclfile', '/data/users.acl']
                : ['mysqld'],
            'environment' => [],
            'ports' => [$driver === 'redis' ? '10.44.0.1:6379:6379' : '10.44.0.1:3306:3306'],
            'volumes' => [['source' => 'orbit-database-data', 'target' => '/data', 'read_only' => false]],
        ],
        'restart_policy' => 'unless-stopped', 'keep_alive' => true, 'restart_on_deploy' => false,
        'desired_state' => $desired, 'status' => 'active',
    ]);

    return DatabaseServer::query()->create([
        'slug' => 'database', 'driver' => $driver, 'node_id' => $node->id, 'process_id' => $process->id,
        'tag' => $driver === 'redis' ? '8.1' : '8.4', 'port' => $driver === 'redis' ? 6379 : 3306,
        'status' => 'active', 'root_password' => 'image-update-admin-password',
    ])->refresh();
}

it('accepts an image-only Valkey tag update and keeps the remaining specification', function (string $desired): void {
    $server = valkeyImageUpdateServer(desired: $desired);
    $process = $server->process;
    $before = $process->getAttributes();
    $serverBefore = $server->getAttributes();
    $config = $process->runtime_config;

    $response = $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'valkey/valkey:8.1.3-alpine'])
        ->assertAccepted()->assertJsonPath('data.kind', 'process.update');

    $config['image'] = 'valkey/valkey:8.1.3-alpine';
    expect($process->refresh()->runtime_config)->toBe($config);
    expect(Arr::except($process->getAttributes(), ['runtime_config', 'status', 'updated_at']))
        ->toBe(Arr::except($before, ['runtime_config', 'status', 'updated_at']));
    expect($server->refresh()->getAttributes())->toBe($serverBefore);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->target_id)->toBe($process->id)
        ->and($operation->input['desired'])->toBe($desired);
})->with(['running', 'stopped']);

it('returns 409 without mutation for every other Valkey Process update', function (array $body): void {
    $server = valkeyImageUpdateServer();
    $process = $server->process;
    $before = $process->getAttributes();
    $serverBefore = $server->getAttributes();

    $this->patchJson('/api/v1/processes/'.$process->id, $body)
        ->assertConflict()->assertJsonPath('error.code', 'process.required_by_database_server');

    expect($process->refresh()->getAttributes())->toBe($before);
    expect($server->refresh()->getAttributes())->toBe($serverBefore);
    expect(Operation::query()->count())->toBe(0);
})->with([
    'another repository' => [['image' => 'redis:8.2']],
    'repository prefix' => [['image' => 'valkey/valkey-other:8.2']],
    'registry prefix' => [['image' => 'docker.io/valkey/valkey:8.2']],
    'repository case' => [['image' => 'Valkey/valkey:8.2']],
    'digest-only reference' => [['image' => 'valkey/valkey@sha256:'.str_repeat('a', 64)]],
    '129-character tag' => [['image' => 'valkey/valkey:'.str_repeat('a', 129)]],
    'untagged' => [['image' => 'valkey/valkey']],
    'empty tag' => [['image' => 'valkey/valkey:']],
    'digest' => [['image' => 'valkey/valkey:8.2@sha256:'.str_repeat('a', 64)]],
    'invalid tag' => [['image' => 'valkey/valkey:-8.2']],
    'command drops ACL file' => [['image' => 'valkey/valkey:8.2', 'command' => ['valkey-server']]],
    'environment override' => [['image' => 'valkey/valkey:8.2', 'environment' => ['VALKEY_EXTRA_FLAGS' => '--aclfile /tmp/open.acl']]],
    'volume removed' => [['image' => 'valkey/valkey:8.2', 'volumes' => []]],
    'ports changed' => [['image' => 'valkey/valkey:8.2', 'ports' => ['10.44.0.1:6380:6379']]],
    'working directory changed' => [['image' => 'valkey/valkey:8.2', 'working_directory' => '/tmp']],
    'runtime provided' => [['image' => 'valkey/valkey:8.2', 'runtime' => 'docker']],
    'restart policy changed' => [['image' => 'valkey/valkey:8.2', 'restart_policy' => 'never']],
    'unchanged extra field' => [['image' => 'valkey/valkey:8.2', 'restart_policy' => 'unless-stopped']],
    'unknown extra field' => [['image' => 'valkey/valkey:8.2', 'extra' => true]],
    'no image' => [['command' => ['valkey-server']]],
    'no fields' => [[]],
]);

it('keeps ordinary partial updates available for a MySQL server Process', function (): void {
    $server = valkeyImageUpdateServer('mysql');
    $process = $server->process;
    $config = $process->runtime_config;

    $response = $this->patchJson('/api/v1/processes/'.$process->id, [
        'image' => 'mysql:8.4.6', 'command' => ['mysqld', '--max-connections=200'],
    ])->assertAccepted()->assertJsonPath('data.kind', 'process.update');

    $config['image'] = 'mysql:8.4.6';
    $config['command'] = ['mysqld', '--max-connections=200'];
    expect($process->refresh()->runtime_config)->toBe($config);
    $update = Operation::query()->findOrFail($response->json('data.id'));
    $replacement = app(ProcessLifecycleInput::class)->forOperation($update)['intent']['installation']['definition'];

    expect($replacement['image'])->toBe('mysql:8.4.6')
        ->and($replacement['command'])->toBe(['mysqld', '--max-connections=200'])
        ->and($replacement['healthcheck_arguments'])->toBe([
            '--health-cmd', 'mysqladmin --protocol=TCP --host=127.0.0.1 ping --silent',
            '--health-interval', '2s', '--health-timeout', '5s', '--health-retries', '3', '--health-start-period', '300s',
        ]);
});

it('validates malformed requests before the Valkey ownership guard with HTTP 422', function (array $body, string $field): void {
    $server = valkeyImageUpdateServer();
    $process = $server->process;
    $before = $process->getAttributes();
    $serverBefore = $server->getAttributes();

    $this->patchJson('/api/v1/processes/'.$process->id, $body)
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    expect($process->refresh()->getAttributes())->toBe($before);
    expect($server->refresh()->getAttributes())->toBe($serverBefore);
    expect(Operation::query()->count())->toBe(0);
})->with([
    'user' => [['image' => 'valkey/valkey:8.2', 'user' => 'orbit'], 'user'],
    'start' => [['image' => 'valkey/valkey:8.2', 'start' => true], 'start'],
    'empty command' => [['image' => 'valkey/valkey:8.2', 'command' => []], 'command'],
    'runtime change' => [['image' => 'valkey/valkey:8.2', 'runtime' => 'systemd'], 'image'],
    'internal whitespace' => [['image' => 'valkey/valkey:8 .2'], 'image'],
]);

it('accepts valid tag lengths and normalized surrounding whitespace for an image-only update', function (string $image, string $stored): void {
    $server = valkeyImageUpdateServer();

    $this->patchJson('/api/v1/processes/'.$server->process_id, ['image' => $image])
        ->assertAccepted();

    expect($server->process->runtime_config['image'])->toBe($stored);
})->with([
    'one character' => ['valkey/valkey:8', 'valkey/valkey:8'],
    '128 characters' => ['valkey/valkey:'.str_repeat('a', 128), 'valkey/valkey:'.str_repeat('a', 128)],
    'leading whitespace' => [' valkey/valkey:8.2', 'valkey/valkey:8.2'],
    'trailing whitespace' => ['valkey/valkey:8.2 ', 'valkey/valkey:8.2'],
]);

it('renders the ACL command mounts and health probe for replacement then completes a server repair', function (): void {
    $server = valkeyImageUpdateServer();
    $response = $this->patchJson('/api/v1/processes/'.$server->process_id, ['image' => 'valkey/valkey:8.2'])
        ->assertAccepted();
    $update = Operation::query()->findOrFail($response->json('data.id'));
    $replacement = app(ProcessLifecycleInput::class)->forOperation($update)['intent']['installation']['definition'];
    $healthcheck = ['--health-cmd', 'valkey-cli PING', '--health-interval', '2s', '--health-timeout', '5s',
        '--health-retries', '3', '--health-start-period', '300s'];

    expect($replacement['image'])->toBe('valkey/valkey:8.2')
        ->and($replacement['command'])->toBe(['valkey-server', '--appendonly', 'yes', '--dir', '/data', '--aclfile', '/data/users.acl'])
        ->and($replacement['mounts'])->toBe([['source' => 'orbit-database-data', 'target' => '/data', 'read_only' => false]])
        ->and($replacement['healthcheck_arguments'])->toBe($healthcheck);
    $update->forceFill(['state' => [...$update->state, 'swamp_run_id' => 'valkey-image-update']])->save();
    app(ProcessControlProjection::class)->apply($update, [
        'status' => 'succeeded', 'run_id' => 'valkey-image-update',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
    ]);

    $repair = app(CreateDatabaseServerAction::class)->execute(new CreateDatabaseServerData(
        $server->slug, $server->node_id, DatabaseDriver::Redis, $server->tag, $server->port,
    ));
    $intent = DatabaseExecutionIntent::recorded($repair->input['intent_id'])->payload['admission']['input'];
    $commands = app(DatabaseServerCommands::class)->render($intent);

    expect($intent['created'])->toBeFalse()
        ->and($intent['container_input']['image'])->toBe('valkey/valkey:8.2')
        ->and($intent['container_input']['healthcheck_arguments'])->toBe($healthcheck);
    expect(array_slice($commands['start'], -8))->toBe(['valkey/valkey:8.2', ...$replacement['command']]);
    expect(array_slice($commands['scrub'], -8))->toBe(['valkey/valkey:8.2', ...$replacement['command']]);
    expect(array_slice($commands['start'], array_search('--health-cmd', $commands['start'], true), 10))->toBe($healthcheck);
    expect(array_slice($commands['scrub'], array_search('--health-cmd', $commands['scrub'], true), 10))->toBe($healthcheck);
    expect($commands['wait'])->toBe(['timeout', '60', 'sh', '-c',
        'until [ "$(docker inspect --format "{{.State.Health.Status}}" "$1")" = healthy ]; do sleep 1; done',
        'sh', $intent['container']]);
    $repair->forceFill(['state' => [...$repair->state, 'swamp_run_id' => 'valkey-repair']])->save();
    app(DatabaseServerCreateProjection::class)->apply($repair, [
        'status' => 'succeeded', 'run_id' => 'valkey-repair',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Check the admin login' => ['stdout' => "PONG\n"]]],
    ]);

    expect($update->refresh()->status->value)->toBe('succeeded');
    expect($repair->refresh()->status->value)->toBe('succeeded');
    expect($server->refresh()->status->value)->toBe('active');
    expect($server->process->status->value)->toBe('active');
    expect($server->process->runtime_config['image'])->toBe('valkey/valkey:8.2');
    expect($server->tag)->toBe('8.1');
});

it('keeps ordinary Docker definitions free of Database server health options', function (): void {
    $server = valkeyImageUpdateServer();
    $process = $server->process;
    $renderer = app(DockerProcessRenderer::class);
    $target = app(ProcessTargetResolver::class)->forInstallation($process);
    $managedHash = $renderer->specHash($process, $target);
    $server->delete();

    $definition = $renderer->containerInput($process, $target);

    expect($definition)->not->toHaveKey('healthcheck_arguments');
    expect($renderer->specHash($process, $target))->not->toBe($managedHash);
});
