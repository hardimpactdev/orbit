<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Operations\OperationData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerDestroyExecutionCopies;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

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

it('wires the lifecycle destroy contribution while Conn is disabled', function (): void {
    app(ExtensionStore::class)->set('conn', false);
    $contributions = (new ReflectionProperty(InstanceLifecycleContributions::class, 'contributions'))->getValue(app(InstanceLifecycleContributions::class));
    expect(collect($contributions)->map(fn (object $contribution): string => $contribution::class)->all())->toBe([ConnInstanceLifecycle::class]);
});

it('deletes the owned server database create and destroy copies but keeps the default copies', function (): void {
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
