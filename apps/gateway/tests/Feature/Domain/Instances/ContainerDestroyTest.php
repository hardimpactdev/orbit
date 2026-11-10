<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
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

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->messages[] = $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
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

function destroyClone(Instance $default): Instance
{
    $clone = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => 'feature',
        'status' => InstanceState::Active, 'branch' => 'feature', 'starting_commit' => str_repeat('c', 40), 'source_layout' => 'checkout', 'checkout_path' => '/tmp/reservation', 'first_setup_pending' => false]);
    $clone->update(['checkout_path' => InstanceContainer::workspaceOnNode($clone)]);
    app(InstanceContainer::class)->reserve($clone, $default->node, $default);

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

it('requires force for clone cleanup until guarded removal is built', function (): void {
    $clone = destroyClone(destroyDefault());
    $before = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($clone, false)))->toBe('instance.container_destroy_force_required')
        ->and(Operation::query()->count())->toBe($before)->and($clone->fresh()->status)->toBe(InstanceState::Active);
});

it('removes container Processes and disabled Conn records and frees the port only after success', function (): void {
    $default = destroyDefault();
    $clone = destroyClone($default);
    $port = $clone->runtime->t3_port;
    $process = Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $clone->id, 'name' => 'worker', 'runtime' => 'systemd',
        'runtime_config' => ['command' => ['node', 'worker.js']], 'status' => 'active']);
    $environment = ConnEnvironment::query()->forceCreate(['instance_id' => $clone->id, 'environment_id' => 'clone-env', 'label' => 'clone',
        'url' => 'http://10.44.0.7:3800', 'node_id' => $clone->node_id, 'admin_session' => 'gateway-private-value',
        'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $pairing = ConnPairing::query()->create(['node_id' => $clone->node_id, 'conn_environment_id' => $environment->id, 'client_label' => 'mac', 'credential' => 'private-link']);
    $create = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $clone->id,
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
    expect($deleted)->toContain('native-gateway-copy', 'instance-create-model-'.$create->id)
        ->not->toContain('instance-create-model-'.Operation::query()->where('target_id', $default->id)->value('id'));
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
