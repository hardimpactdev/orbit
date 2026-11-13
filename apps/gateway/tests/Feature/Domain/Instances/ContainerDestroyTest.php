<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Operations\OperationData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerDestroyExecutionCopies;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->socket = new class implements WebSocketClient
    {
        public array $messages = [];

        public array $values = [];

        public int $connects = 0;

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->connects++;
        }

        public function send(array $message): void
        {
            $this->messages[] = $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $key = $this->last['payload']['key'] ?? '';
            if ($this->last['type'] === 'vault.put') {
                $this->values[$key] = $this->last['payload']['value'];
            }
            if ($this->last['type'] === 'vault.delete') {
                if (! array_key_exists($key, $this->values)) {
                    return [['id' => $this->last['id'], 'type' => 'error', 'error' => ['code' => 'not_found']]];
                }
                unset($this->values[$key]);
            }

            return [['id' => $this->last['id'], 'type' => $this->last['type'], 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs',
                'secretKey' => $this->last['payload']['key'] ?? '', 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->socket);
    // The replication slot read of removal admission: no slot unless a test says otherwise.
    $this->slots = new class implements SshExecutor
    {
        public array $calls = [];

        public ?Closure $answer = null;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = [$connection, $command, stream_get_contents($command->protectedInput?->stream() ?? fopen('php://memory', 'r'))];

            return $this->answer === null ? new CommandResult(0, "orbit.slots\n", '', 1, false) : ($this->answer)($command);
        }
    };
    app()->instance(SshExecutor::class, $this->slots);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'test-token']);
});

function destroyDefault(): Instance
{
    $create = containerCreate(containerDefaultProject(), containerDefaultNode());
    $create->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
    $default = Instance::query()->findOrFail($create->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => 'main', 'starting_commit' => str_repeat('c', 40)]);
    $default->runtime()->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

function destroyClone(Instance $default, bool $migrated = false): Instance
{
    $clone = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => 'feature',
        'status' => InstanceState::Active, 'branch' => 'feature', 'starting_commit' => str_repeat('c', 40), 'source_layout' => 'checkout', 'checkout_path' => '/tmp/reservation', 'first_setup_pending' => false]);
    $clone->update(['checkout_path' => InstanceContainer::workspaceOnNode($clone)]);
    app(InstanceContainer::class)->reserve($clone, $default->node, $migrated ? null : $default);

    return $clone->refresh();
}

function destroyTerminal(Operation $operation, string $status, array $result = []): array
{
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'destroy-run'], 'finished_at' => null])->save();

    return ['status' => $status, 'run_id' => 'destroy-run', 'result' => $result, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()];
}

it('keeps container default destruction unavailable in this clone-only slice', function (bool $force): void {
    $default = destroyDefault();
    $before = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($default, $force)))->toBe('operation.kind_unavailable')
        ->and(Operation::query()->count())->toBe($before)->and($default->fresh()->status)->toBe(InstanceState::Active);
})->with([false, true]);

it('requires force for non-default cleanup until guarded removal is built', function (bool $migrated): void {
    $clone = destroyClone(destroyDefault(), $migrated);
    $before = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($clone, false)))->toBe('instance.container_destroy_force_required')
        ->and(Operation::query()->count())->toBe($before)->and($clone->fresh()->status)->toBe(InstanceState::Active);
})->with(['clone' => false, 'migrated checkout' => true]);

it('removes container Processes and disabled Conn records and frees the port only after success', function (bool $migrated): void {
    $default = destroyDefault();
    $clone = destroyClone($default, $migrated);
    $port = $clone->runtime->t3_port;
    $process = Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $clone->id, 'name' => 'worker', 'runtime' => 'systemd',
        'runtime_config' => ['command' => ['node', 'worker.js']], 'status' => 'active']);
    $environment = ConnEnvironment::query()->forceCreate(['instance_id' => $clone->id, 'environment_id' => 'clone-env', 'label' => 'clone',
        'url' => 'http://10.44.0.7:3800', 'node_id' => $clone->node_id, 'admin_session' => 'gateway-private-value',
        'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $pairing = ConnPairing::query()->create(['node_id' => $clone->node_id, 'conn_environment_id' => $environment->id, 'client_label' => 'mac', 'credential' => 'private-link']);
    $create = Operation::query()->create(['kind' => $migrated ? 'instance.migrate' : 'instance.create', 'target_type' => 'instance', 'target_id' => $clone->id,
        'node_id' => $clone->node_id, 'status' => 'succeeded', 'input' => [], 'state' => [],
        'result' => ['t3' => ['gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'native-gateway-copy']]]]);
    app(ExtensionStore::class)->set('conn', false);
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    expect($operation->input['direct_destroy']['runtime']['processes'][0]['process_id'])->toBe($process->id)
        ->and($clone->runtime()->value('t3_port'))->toBe($port);
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'succeeded'));
    expect($clone->fresh())->toBeNull()->and($process->fresh())->toBeNull()->and($environment->fresh())->toBeNull()->and($pairing->fresh())->toBeNull()
        ->and($default->fresh()->status)->toBe(InstanceState::Active);
    $next = destroyClone($default);
    expect($next->runtime->t3_port)->toBe($port);
    expect($default->runtime()->exists())->toBeTrue();
    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key')->all();
    expect($deleted)->toContain('native-gateway-copy', ($migrated ? 'instance-migrate-model-' : 'instance-create-model-').$create->id)
        ->toContain(($migrated ? 'instance-migrate-processes-' : 'instance-create-processes-').$create->id)
        ->not->toContain('instance-create-model-'.Operation::query()->where('target_id', $default->id)->value('id'));
})->with(['clone' => false, 'migrated checkout' => true]);

it('keeps retained Git findable in the destroy result after deleting a migrated Instance', function (): void {
    $clone = destroyClone(destroyDefault(), true);
    $path = '/home/orbit/apps/tool/feature.orbit-retired-git';
    $at = '2026-11-10T19:00:00+00:00';
    $clone->update(['retained_git_path' => $path, 'retained_git_at' => $at]);
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $terminal = destroyTerminal($operation, 'succeeded');

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, $terminal);

    expect($clone->fresh())->toBeNull()
        ->and(OperationData::summary($operation->fresh())->result)->toBe([
            'removed' => true, 'retained_git_path' => $path, 'retained_git_at' => $at,
        ]);
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation->fresh(), $terminal);
    expect($operation->fresh()->result)->toBe([
        'removed' => true, 'retained_git_path' => $path, 'retained_git_at' => $at,
    ]);
});

it('keeps a failed removal retryable without freeing the port', function (): void {
    $default = destroyDefault();
    $clone = destroyClone($default);
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $failure = ['summary' => ['failed_steps' => ['Delete the container']], 'steps' => ['Delete the container' => ['exit_code' => 1]]];
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'failed', $failure));
    expect($operation->fresh()->error_code)->toBe('instance.removal_incomplete')->and($clone->fresh()->status)->toBe(InstanceState::Removing)
        ->and($clone->runtime()->exists())->toBeTrue();
    $retry = app(StartInstanceDestroyAction::class)->execute($clone->fresh(), true);
    expect($retry->input['removal_id'])->toBe($operation->input['removal_id'])->and($retry->input['force'])->toBeTrue();
    app(DevelopmentInstanceDestroyProjection::class)->apply($retry, destroyTerminal($retry, 'succeeded'));
    expect($clone->fresh())->toBeNull();
});

it('deletes every execution copy of a removed Instance over one connection', function (): void {
    $clone = destroyClone(destroyDefault());
    foreach (range(1, 60) as $index) {
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
            'status' => 'succeeded', 'input' => [], 'state' => [], 'result' => ['copy' => ['vault' => 'orbit-control-inputs', 'key' => 'deploy-copy-'.$index]]]);
    }
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $terminal = destroyTerminal($operation, 'succeeded');
    $this->socket->connects = 0;
    $this->socket->messages = [];

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, $terminal);

    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key');
    // Serve admits 50 connections from the Gateway's address a minute; one per key refused the next destroys' starts.
    expect($deleted->count())->toBeGreaterThan(60)->and($deleted)->toContain('deploy-copy-60')
        ->and($this->socket->connects)->toBe(1)->and($clone->fresh())->toBeNull();
});

it('leaves a destroy whose start failed for good as a failed removal that a new destroy starts again', function (): void {
    $default = destroyDefault();
    $clone = destroyClone($default);
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now()])->save();

    app(StartSwampRun::class)->refuse($operation, new ResourceOperationException('swamp.start_unavailable', 'The native workflow start could not connect.', 503));

    $removal = InstanceRemoval::query()->findOrFail($operation->input['removal_id']);
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($removal->status)->toBe(InstanceRemovalStatus::Failed)
        ->and($removal->error_code)->toBe('swamp.start_unavailable')
        ->and($removal->current_step)->toBe(InstanceRemovalStep::SourcePreparation)
        ->and($removal->failed_step)->toBe(InstanceRemovalStep::SourcePreparation)
        ->and($clone->fresh()->status)->toBe(InstanceState::Removing)
        ->and($clone->runtime()->exists())->toBeTrue();

    $retry = app(StartInstanceDestroyAction::class)->execute($clone->fresh(), true);
    expect($retry->id)->not->toBe($operation->id)->and($retry->status)->toBe(OperationStatus::Queued)
        ->and($retry->input['removal_id'])->toBe($operation->input['removal_id'])
        ->and($removal->fresh()->status)->toBe(InstanceRemovalStatus::Removing);
    app(DevelopmentInstanceDestroyProjection::class)->apply($retry, destroyTerminal($retry, 'succeeded'));
    expect($clone->fresh())->toBeNull()->and($removal->fresh()->status)->toBe(InstanceRemovalStatus::Completed);
});

it('keeps the failed step of an earlier run when a retried destroy cannot start', function (): void {
    $clone = destroyClone(destroyDefault());
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $failure = ['summary' => ['failed_steps' => ['Stop, remove Processes']], 'steps' => ['Stop, remove Processes' => ['exit_code' => 1]]];
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'failed', $failure));
    $retry = app(StartInstanceDestroyAction::class)->execute($clone->fresh(), true);
    $retry->forceFill(['status' => OperationStatus::Running, 'started_at' => now()])->save();

    app(StartSwampRun::class)->refuse($retry, new ResourceOperationException('swamp.start_unavailable', 'The native workflow start could not connect.', 503));

    $removal = InstanceRemoval::query()->findOrFail($operation->input['removal_id']);
    expect($removal->status)->toBe(InstanceRemovalStatus::Failed)->and($removal->error_code)->toBe('swamp.start_unavailable')
        ->and($removal->current_step)->toBe(InstanceRemovalStep::RuntimeCleanup)->and($removal->failed_step)->toBe(InstanceRemovalStep::RuntimeCleanup);
});

it('wires the lifecycle destroy contribution while Conn is disabled', function (): void {
    app(ExtensionStore::class)->set('conn', false);
    $contributions = (new ReflectionProperty(InstanceLifecycleContributions::class, 'contributions'))->getValue(app(InstanceLifecycleContributions::class));
    expect(collect($contributions)->map(fn (object $contribution): string => $contribution::class)->all())->toBe([ConnInstanceLifecycle::class]);
});

/** A container clone that owns a PostgreSQL database on a Database server; its default owns another.
 * @return array{Instance, DatabaseServer, Operation, Operation}
 */
function ownedPostgresClone(): array
{
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    $node = containerDefaultNode();
    $process = Process::query()->forceCreate(['owner_type' => 'node', 'owner_id' => $node->id, 'name' => 'db-pg', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'postgres:18', 'ports' => [$node->wireguard_ip.':5432:5432']], 'status' => 'active']);
    $server = DatabaseServer::query()->forceCreate(['slug' => 'pg', 'driver' => DatabaseDriver::Pgsql, 'node_id' => $node->id,
        'process_id' => $process->id, 'tag' => '18', 'port' => 5432, 'status' => 'active', 'root_password' => 'root-secret']);
    $defaultCreate = containerCreate(containerDefaultProject(), $node, 'default', 'pg');
    app(DevelopmentInstanceCreateProjection::class)->input($defaultCreate);
    $default = Instance::query()->findOrFail($defaultCreate->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => 'main', 'starting_commit' => str_repeat('c', 40)]);
    $default->runtime()->update(['seed_commit' => str_repeat('c', 40)]);
    $defaultCreate->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
    $create = containerCreate($default->project, $node, 'feature');
    app(DevelopmentInstanceCreateProjection::class)->input($create);
    $clone = Instance::query()->findOrFail($create->target_id);
    $clone->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => 'feature', 'starting_commit' => str_repeat('c', 40)]);
    $create->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();

    return [$clone, $server, $create, $defaultCreate];
}

it('deletes the owned server database create and destroy copies but keeps the default copies', function (): void {
    [$clone, $server, $create, $defaultCreate] = ownedPostgresClone();
    $default = Instance::query()->findOrFail($defaultCreate->target_id);
    $cloneIntent = $create->input['direct_provisioning']['server_database']['intent_id'];
    $defaultIntent = $defaultCreate->input['direct_provisioning']['server_database']['intent_id'];
    expect($this->socket->values)->toHaveKeys(['database-create-intent-'.$cloneIntent, 'database-create-owner-'.$cloneIntent,
        'database-create-intent-'.$defaultIntent, 'database-create-owner-'.$defaultIntent]);
    $destroy = app(StartInstanceDestroyAction::class)->execute($clone, true);
    app(DevelopmentInstanceDestroyProjection::class)->input($destroy);
    $destroyIntent = $destroy->input['direct_destroy']['database']['intent_id'];
    $batches = array_map(fn (string $batch): string => 'database-destroy-owned-'.$destroyIntent.'-'.$server->id.'-'.$batch, ['fence', 'drop', 'gone', 'restore']);
    expect($this->socket->values)->toHaveKeys($batches);
    // One copy was already removed: a caller retry must accept native not_found and continue.
    unset($this->socket->values['database-create-owner-'.$cloneIntent]);
    app(DevelopmentInstanceDestroyProjection::class)->apply($destroy, destroyTerminal($destroy, 'succeeded'));
    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key')->all();
    expect($deleted)->toContain('database-create-intent-'.$cloneIntent, 'database-create-owner-'.$cloneIntent, ...$batches)
        ->and($this->socket->values)->toHaveKeys(['database-create-intent-'.$defaultIntent, 'database-create-owner-'.$defaultIntent])
        ->and($this->socket->values)->not->toHaveKeys(['database-create-intent-'.$cloneIntent, ...$batches])
        ->and(DatabaseConnection::query()->where('owner_instance_id', $clone->id)->exists())->toBeFalse();
    app(ContainerDestroyExecutionCopies::class)->remove($destroy->fresh());
    expect($default->fresh())->not->toBeNull();
});

it('deletes a clone\'s own Valkey user and its copies, never the default\'s shared user', function (): void {
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    $node = containerDefaultNode();
    $process = Process::query()->forceCreate(['owner_type' => 'node', 'owner_id' => $node->id, 'name' => 'kv', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'valkey/valkey:8.1', 'ports' => [$node->wireguard_ip.':6379:6379']], 'status' => 'active']);
    $server = DatabaseServer::query()->forceCreate(['slug' => 'kv', 'driver' => DatabaseDriver::Redis, 'node_id' => $node->id,
        'process_id' => $process->id, 'tag' => '8.1', 'port' => 6379, 'status' => 'active', 'root_password' => 'root-secret']);
    $defaultCreate = containerCreate(containerDefaultProject(), $node);
    $default = Instance::query()->findOrFail($defaultCreate->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => 'main', 'starting_commit' => str_repeat('c', 40)]);
    $default->runtime()->update(['seed_commit' => str_repeat('c', 40)]);
    $shared = DatabaseConnection::query()->create(['slug' => 'shared-redis', 'driver' => 'redis', 'node_id' => $node->id, 'database_server_id' => $server->id,
        'host' => '10.44.0.7', 'port' => 6379, 'username' => 'shared-redis', 'password' => 'default-secret']);
    $shared->users()->create(['username' => 'shared-redis', 'created_by' => 'beast', 'privileges' => 'on ~tool_* resetchannels -@all +@read -@admin']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => 'REDIS', 'database_connection_id' => $shared->id, 'network' => 'wireguard']);
    $create = containerCreate($default->project, $node, 'feature');
    app(DevelopmentInstanceCreateProjection::class)->input($create);
    $clone = Instance::query()->findOrFail($create->target_id);
    $clone->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => 'feature', 'starting_commit' => str_repeat('c', 40)]);
    $create->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
    $intent = $create->input['direct_provisioning']['server_valkey']['intent_id'];
    expect($this->socket->values)->toHaveKeys(['database-create-intent-'.$intent, 'database-create-owner-'.$intent]);

    $destroy = app(StartInstanceDestroyAction::class)->execute($clone, true);
    app(DevelopmentInstanceDestroyProjection::class)->input($destroy);
    $drop = $this->socket->values['database-destroy-owned-'.$destroy->input['direct_destroy']['database']['intent_id'].'-'.$server->id.'-drop'];
    app(DevelopmentInstanceDestroyProjection::class)->apply($destroy, destroyTerminal($destroy, 'succeeded'));
    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key')->all();

    expect($drop)->toContain("ACL DELUSER tool-feature-valkey\n")->not->toContain('shared-redis')
        ->and($deleted)->toContain('database-create-intent-'.$intent, 'database-create-owner-'.$intent)
        ->and(DatabaseConnection::query()->where('owner_instance_id', $clone->id)->exists())->toBeFalse()
        ->and($shared->fresh())->not->toBeNull()
        ->and($shared->targets()->pluck('instance_id')->all())->toBe([$default->id])
        // A Valkey server has no replication slots: removal admission reads nothing.
        ->and($this->slots->calls)->toBe([]);
});

/** @return array<string, mixed> The removal facts that a refused admission must leave as they were. */
function slotGuardState(Instance $clone): array
{
    return ['instance' => $clone->fresh()->status, 'operations' => Operation::query()->where('kind', 'instance.destroy')->count(),
        'removals' => InstanceRemoval::query()->count(), 'routes' => Route::query()->orderBy('id')->get(['id', 'status', 'sites_published'])->toArray(),
        'holds' => DB::table('route_publication_holds')->count()];
}

it('reads the planned databases\' replication slots with the drop\'s admin client, then admits a removal without a slot', function (): void {
    [$clone, $server] = ownedPostgresClone();

    $destroy = app(StartInstanceDestroyAction::class)->execute($clone, true);
    // Every name the drop plans: the database, its test database and the test pool.
    $planned = $destroy->input['direct_destroy']['database']['servers'][0]['intent']['databases'];
    $names = implode(',', array_map(fn (string $name): string => "'{$name}'", $planned));

    expect($this->slots->calls)->toHaveCount(1);
    [$connection, $command, $stdin] = $this->slots->calls[0];
    $container = app(DockerProcessRenderer::class)->containerName($server->process);
    expect($connection->host)->toBe($server->node->wireguard_ip)->and($connection->user)->toBe($server->node->user)
        ->and($connection->connectTimeout)->toBe(2)->and($connection->commandTimeout)->toBe(5.0)
        ->and($command->timeout)->toBe(5.0)
        // As the drop runs it: `sudo: true` on the server's Node, then its admin client (Node 17's user has no Docker group).
        ->and($command->arguments)->toBe(['timeout', '4', 'sudo', '-n', 'docker', 'exec', '-i', $container, 'sh', '-c',
            'IFS= read -r PGPASSWORD && export PGPASSWORD && exec psql --no-psqlrc --host=127.0.0.1 --username="$1" --dbname="$2" --no-align --tuples-only --quiet --set=ON_ERROR_STOP=1',
            'sh', 'postgres', 'postgres'])
        ->and($command->input)->toBeNull()
        ->and($stdin)->toBe("root-secret\nSELECT 'orbit.slots';\nSELECT slot_name::text || '|' || database::text || '|' || slot_type::text || '|' || active::text FROM pg_replication_slots WHERE database IN ({$names}) ORDER BY database, slot_name;\n")
        ->and($planned)->toContain(DatabaseConnection::query()->where('owner_instance_id', $clone->id)->sole()->database)
        ->and($destroy->kind)->toBe('instance.destroy')
        ->and($clone->fresh()->status)->toBe(InstanceState::Removing);
});

it('refuses a removal whose database has a replication slot before any effect, also with --force', function (string $active): void {
    [$clone] = ownedPostgresClone();
    $database = DatabaseConnection::query()->where('owner_instance_id', $clone->id)->sole()->database;
    $this->slots->answer = fn (): CommandResult => new CommandResult(0, "orbit.slots\npowersync_feature|{$database}|logical|{$active}\n", '', 1, false);
    $before = slotGuardState($clone);
    $messages = count($this->socket->messages);

    try {
        app(StartInstanceDestroyAction::class)->execute($clone, true);
        $this->fail('The removal was admitted.');
    } catch (ResourceOperationException $refusal) {
        expect($refusal->errorCode)->toBe('instance.database_replication_slot')->and($refusal->status)->toBe(409)
            ->and($refusal->getMessage())->toContain("Database [{$database}]", 'replication slot [powersync_feature]')
            ->and($refusal->details['slots'])->toBe([['server_id' => DatabaseServer::query()->sole()->id, 'server' => 'pg', 'database' => $database,
                'slot' => 'powersync_feature', 'type' => 'logical', 'active' => $active === 'true']]);
    }

    // No Schedule trigger removal, vault copy, Operation, status, Route or hold changed.
    expect(slotGuardState($clone))->toBe($before)->and($before['instance'])->toBe(InstanceState::Active)
        ->and(count($this->socket->messages))->toBe($messages);
})->with(['active slot' => 'true', 'inactive slot' => 'false']);

it('refuses a removal when the replication slot read fails, without the admin password in the refusal', function (Closure $answer): void {
    [$clone] = ownedPostgresClone();
    $this->slots->answer = $answer;
    $before = slotGuardState($clone);

    try {
        app(StartInstanceDestroyAction::class)->execute($clone, true);
        $this->fail('The removal was admitted.');
    } catch (ResourceOperationException $refusal) {
        expect($refusal->errorCode)->toBe('instance.database_slots_unreadable')->and($refusal->status)->toBe(503)
            ->and($refusal->getMessage())->toContain('Database server [pg]')->not->toContain('root-secret')
            ->and(json_encode($refusal->details, JSON_THROW_ON_ERROR))->not->toContain('root-secret')
            ->and($refusal->getPrevious())->toBeNull();
    }

    expect(slotGuardState($clone))->toBe($before);
})->with([
    'unreachable' => fn () => fn (): never => throw new RuntimeException('ssh: connect timed out; password root-secret'),
    'client failed' => fn () => fn (): CommandResult => new CommandResult(2, '', 'psql: password authentication failed (root-secret)', 1, false),
    'no marker' => fn () => fn (): CommandResult => new CommandResult(0, '', '', 1, false),
    'unknown row' => fn () => fn (): CommandResult => new CommandResult(0, "orbit.slots\nslot|other_database|logical|true\n", '', 1, false),
    'truncated' => fn () => fn (): CommandResult => new CommandResult(0, "orbit.slots\n", '', 1, true),
]);

it('reads the replication slots again when a failed removal is retried', function (): void {
    [$clone] = ownedPostgresClone();
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'failed',
        ['summary' => ['failed_steps' => ['Drop owned databases']], 'steps' => ['Drop owned databases' => ['exit_code' => 3]]]));
    $database = DatabaseConnection::query()->where('owner_instance_id', $clone->id)->sole()->database;
    $this->slots->answer = fn (): CommandResult => new CommandResult(0, "orbit.slots\nlate_slot|{$database}|logical|false\n", '', 1, false);
    $before = slotGuardState($clone);

    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($clone->fresh(), true)))->toBe('instance.database_replication_slot')
        ->and(slotGuardState($clone))->toBe($before)->and($before['instance'])->toBe(InstanceState::Removing)
        ->and($this->slots->calls)->toHaveCount(2);
});

it('names the planned databases in the slot read only through the drop\'s identifier check and quoting', function (): void {
    expect(app(DatabaseStatements::class)->replicationSlots(['app', 'app_test_1']))
        ->toContain("WHERE database IN ('app','app_test_1')");

    try {
        app(DatabaseStatements::class)->replicationSlots(["app') OR ('1'='1"]);
        $this->fail('A quoted name reached the statement.');
    } catch (ResourceOperationException $refusal) {
        expect($refusal->errorCode)->toBe('database.server_admin_invalid');
    }
});
