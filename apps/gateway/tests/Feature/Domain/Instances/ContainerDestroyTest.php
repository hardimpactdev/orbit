<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceUnregisterAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\MachineBus;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerDestroyExecutionCopies;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDestroyEngineFlow;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRemovalReaper;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeDevelopmentInstanceInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Engine\EngineBus;
use App\Infrastructure\Executor\ExecutorJournalLookup;
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
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Process;
use App\Models\Route;
use Basis\Nats\Client;
use Basis\Nats\Message\Payload;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
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
            if ($this->last['type'] === 'workflow.run') {
                return [['id' => $this->last['id'], 'type' => 'event', 'event' => ['kind' => 'started',
                    'workflowName' => 'operation-instance-destroy', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
            }
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

function signedDestroy(Instance $clone): Operation
{
    $clone->node->accessibleNodes()->syncWithoutDetaching([$clone->node_id]);
    $id = test()->withServerVariables(['REMOTE_ADDR' => $clone->node->wireguard_ip])
        ->deleteJson('/api/v1/instances/'.$clone->id, ['force' => true])->assertAccepted()->json('data.id');

    return Operation::query()->findOrFail($id);
}

function configureDestroyEngine(): void
{
    $pair = sodium_crypto_sign_keypair();
    config(['orbit.engine.kinds' => ['instance.destroy'], 'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
}

function destroyBus(): MockInterface
{
    configureDestroyEngine();

    return test()->mock(MachineBus::class);
}

function destroyOrderBus(Closure $dispatch, ?Closure $subscribe = null): void
{
    configureDestroyEngine();
    $client = new class($dispatch, $subscribe) extends Client
    {
        public function __construct(private readonly Closure $respond, private readonly ?Closure $subscribe)
        {
            parent::__construct();
        }

        public function dispatch(string $name, mixed $payload, ?float $timeout = null): mixed
        {
            return ($this->respond)($name, $payload);
        }

        public function ping(): bool
        {
            return true;
        }

        public function subscribeRequests(bool $force = false): void
        {
            $this->subscribe?->__invoke();
        }
    };
    app()->instance(MachineBus::class, new EngineBus(static fn (): Client => $client));
}

it('holds the signed removal and the Instance lock until the executor repeats its terminal receipt', function (): void {
    $default = destroyDefault();
    $clone = destroyClone($default);
    $savedRuntime = $clone->runtime()->firstOrFail();
    $operation = null;
    $sent = [];
    $recordedExpiries = [];
    $subjects = [];
    destroyOrderBus(function (string $subject, mixed $payload) use ($clone, &$operation, &$sent, &$recordedExpiries, &$subjects): object {
        $subjects[] = $subject;
        if (str_starts_with($subject, '$JS.API.DIRECT.GET.KV_NODES.')) {
            return new Payload('{"actions":["instance.destroy"]}');
        }
        if (str_starts_with($subject, '$JS.API.CONSUMER.CREATE.ORDERS.')) {
            return (object) [];
        }
        if ($subject === 'orbit.engine.start') {
            throw new RuntimeException('start acknowledgement lost');
        }
        if ($subject === "orbit.node.{$clone->node_id}.orders" && $payload instanceof Payload) {
            $recordedExpiries[] = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail()->first_order_expires_ms;
            $sent[] = json_decode((string) base64_decode(explode('.', $payload->body)[0], true), true, flags: JSON_THROW_ON_ERROR);
            if (count($sent) === 1) {
                throw new RuntimeException('order publish acknowledgement lost');
            }
        }

        return new Payload('{"seq":1}');
    });
    $operation = signedDestroy($clone);
    $lock = OperationStore::lockKey('instance', $clone->id);
    app(StartSwampRun::class)->start($operation);
    $operation->refresh();
    expect($operation->state['swamp_run_id'])->toBe(EngineRun::runIdOf($operation->id))
        ->and($operation->lock_key)->toBe($lock)->and($clone->refresh()->status)->toBe(InstanceState::Removing);
    $engine = app(Engine::class);
    $engine->nudgeIfStalled($operation);
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect(array_slice($subjects, -3))->toBe(["orbit.engine.schedule.{$operation->id}.remove.1",
        '$JS.API.CONSUMER.CREATE.ORDERS.node-'.$clone->node_id, "orbit.node.{$clone->node_id}.orders"]);
    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toMatchArray(['action' => 'instance.destroy', 'target' => (string) $clone->id, 'operation' => $operation->id.':remove',
            'input' => ['project' => $savedRuntime->incus_project, 'name' => $savedRuntime->container_name,
                'pool' => 'orbit-instances', 'acl' => $savedRuntime->container_name]]);

    app(CancelOperationAction::class)->execute($operation);
    $this->travel(601)->seconds();
    app(ExpireOperationAction::class)->execute($operation->refresh());
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($sent)->toHaveCount(2)->and($sent[1]['nonce'])->not->toBe($sent[0]['nonce'])
        ->and($recordedExpiries)->toBe([$sent[0]['expires_ms'], $sent[0]['expires_ms']])
        ->and($sent[1]['input'])->toBe($sent[0]['input'])->and($sent[1]['operation'])->toBe($sent[0]['operation']);
    $line = ['action' => 'instance.destroy', 'target' => (string) $clone->id, 'operation' => $operation->id.':remove'];
    $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$line, 'status' => 'refused', 'reason' => 'action_not_enabled']]);
    $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$line, 'status' => 'failed', 'code' => 'executor.order_expired']]);
    $engine->onReceipt(['node' => $clone->node_id + 1, 'line' => [...$line, 'status' => 'done']]);
    $replay = ['status' => 'refused', 'reason' => 'replayed_nonce', 'operation' => $line['operation']];
    foreach (['operation' => ($operation->id + 1).':remove', 'target' => (string) $default->id, 'action' => 'instance.stop'] as $field => $wrong) {
        $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$replay, 'first' => [...$line, 'status' => 'done', $field => $wrong]]]);
    }
    $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$replay, 'first' => [...$line, 'status' => 'done', 'operation' => $operation->id.':other']]]);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)->and($operation->lock_key)->toBe($lock)
        ->and($clone->runtime()->exists())->toBeTrue()
        ->and(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($clone, true)))->toBe('operation.target_busy');

    $receipt = ['node' => $clone->node_id, 'line' => [...$replay, 'first' => [...$line, 'status' => 'done', 'repeat' => true]]];
    $engine->onReceipt($receipt);
    $engine->onReceipt($receipt);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)->and($operation->lock_key)->toBeNull()
        ->and($operation->result)->toBe(['removed' => true])->and($clone->fresh())->toBeNull()->and($default->runtime()->exists())->toBeTrue()
        ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail()->late)->toBe(1)
        ->and(array_column($this->socket->messages, 'type'))->not->toContain('workflow.run');
});

it('cancels signed removal that never reached publication', function (string $point): void {
    $clone = destroyClone(destroyDefault());
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->once()->andReturn(['instance.destroy']);
    $bus->shouldReceive('publishStart', 'nudge')->once();
    $bus->shouldNotReceive('publishOrder');
    $operation = signedDestroy($clone);
    $engine = app(Engine::class);
    app(StartSwampRun::class)->start($operation);
    $key = config('orbit.executor.signing_key');
    if ($point === 'signer unavailable') {
        config(['orbit.executor.signing_key' => null]);
        $bus->shouldNotReceive('scheduleDeadline');
    } else {
        $bus->shouldReceive('scheduleDeadline')->once()->andThrow(new RuntimeException('deadline not published'));
    }
    $engine->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    config(['orbit.executor.signing_key' => $key]);
    $step = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail();
    expect($step->first_sent_ms)->not->toBeNull()->and($step->first_order_expires_ms)->toBeNull()
        ->and($operation->refresh()->lock_key)->not->toBeNull();
    if ($operation->cancel_requested_at === null) {
        $engine->onReceipt(['node' => $clone->node_id, 'line' => ['operation' => $operation->id.':remove',
            'action' => 'instance.destroy', 'target' => (string) $clone->id, 'status' => 'done']]);
        expect($operation->refresh()->status)->toBe(OperationStatus::Running);
        app(CancelOperationAction::class)->execute($operation);
    }
    $this->travel(16)->seconds();
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)->and($operation->lock_key)->toBeNull()
        ->and($clone->runtime()->exists())->toBeTrue()->and($step->refresh()->sends)->toBe(1);
})->with(['signer unavailable', 'deadline unavailable']);

it('cancels before the signed order is handed to NATS', function (string $point): void {
    $clone = destroyClone(destroyDefault());
    $operation = null;
    $subjects = [];
    $consumerExpiries = [];
    destroyOrderBus(function (string $subject) use ($point, &$operation, &$subjects, &$consumerExpiries): object {
        $subjects[] = $subject;
        if (str_starts_with($subject, '$JS.API.DIRECT.GET.KV_NODES.')) {
            return new Payload('{"actions":["instance.destroy"]}');
        }
        if (str_starts_with($subject, 'orbit.engine.schedule.') && $point === 'cancel while scheduling the deadline') {
            app(CancelOperationAction::class)->execute($operation);
        }
        if (str_starts_with($subject, '$JS.API.CONSUMER.CREATE.ORDERS.')) {
            $consumerExpiries[] = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail()->first_order_expires_ms;
            if ($point === 'consumer setup failed') {
                throw new RuntimeException('consumer setup failed');
            }
            if ($point === 'cancel during consumer setup') {
                app(CancelOperationAction::class)->execute($operation);
            }

            return (object) [];
        }

        return new Payload('{"seq":1}');
    }, function () use ($point, &$operation): void {
        if ($point === 'cancel during reply subscription setup') {
            app(CancelOperationAction::class)->execute($operation);
        }
    });
    $operation = signedDestroy($clone);
    app(StartSwampRun::class)->start($operation);
    $engine = app(Engine::class);
    $engine->onEvent('orbit.engine.start', ['operation' => $operation->id]);

    $step = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail();
    expect($consumerExpiries)->toBe([null])->and($step->first_order_expires_ms)->toBeNull()
        ->and($operation->refresh()->lock_key)->toBe(OperationStore::lockKey('instance', $clone->id));
    if ($point === 'consumer setup failed') {
        app(CancelOperationAction::class)->execute($operation);
    }
    $this->travel(16)->seconds();
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);

    expect($subjects)->not->toContain("orbit.node.{$clone->node_id}.orders")
        ->and($operation->refresh()->status)->toBe(OperationStatus::Cancelled)->and($operation->lock_key)->toBeNull()
        ->and($step->refresh()->sends)->toBe(1)->and($clone->runtime()->exists())->toBeTrue();
})->with(['consumer setup failed', 'cancel during consumer setup', 'cancel during reply subscription setup', 'cancel while scheduling the deadline']);

it('starts the entire Swamp destroy if its admitted plan or executor is outside the slice', function (string $case): void {
    $clone = destroyClone(destroyDefault(), migrated: $case === 'not a clone');
    if ($case === 'process') {
        Process::query()->create(['node_id' => $clone->node_id, 'name' => 'worker', 'owner_type' => Instance::MorphAlias, 'owner_id' => $clone->id,
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['node', 'worker.js']], 'status' => 'active']);
    }
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->with($clone->node_id)->times($case === 'not advertised' ? 1 : 0)->andReturn(['instance.stop']);
    $bus->shouldNotReceive('publishStart', 'publishOrder');
    $operation = signedDestroy($clone);
    app(StartSwampRun::class)->start($operation);
    $run = array_values(array_filter($this->socket->messages, static fn (array $message): bool => $message['type'] === 'workflow.run'));
    expect($run)->toHaveCount(1)->and($run[0]['payload']['workflowIdOrName'])->toBe('operation-instance-destroy')
        ->and(Engine::owns($operation->refresh()))->toBeFalse()
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
})->with(['not a clone', 'process', 'not advertised']);

it('does not select signed removal for other admitted obligations or altered ownership', function (string $path, mixed $value): void {
    $clone = destroyClone(destroyDefault());
    $bus = destroyBus();
    $bus->shouldNotReceive('nodeActions');
    $operation = signedDestroy($clone);
    $input = $operation->input;
    data_set($input, $path, $value);
    $operation->input = $input;

    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();
})->with([
    'route' => ['members.0.route_id', 42],
    'secondary route' => ['additional_route_ids', [42]],
    'server, including Valkey' => ['direct_destroy.database.servers', [['server_id' => 8]]],
    'SQLite' => ['direct_destroy.database.sqlite', [['id' => 8]]],
    'custom teardown' => ['direct_destroy.teardown', [['id' => 8]]],
    'pending create or completion' => ['after_operation_id', 8],
    'CI hold' => ['container_destroy.ci_hold', true],
    'default' => ['name', 'default'],
    'unforced' => ['force', false],
    'different owned volume' => ['container_destroy.inner_volume', 'inner-987654'],
]);

it('stops resending before an order could outlive the journal and projects a late terminal refusal', function (int $offset, int $attempts): void {
    $clone = destroyClone(destroyDefault());
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->once()->andReturn(['instance.destroy']);
    $bus->shouldReceive('publishStart', 'nudge')->once();
    $bus->shouldReceive('scheduleDeadline')->times($attempts);
    $expiries = [];
    $bus->shouldReceive('publishOrder')->times($attempts)->andReturnUsing(function (int $node, SignedExecutorOrder $order, Closure $claim) use (&$expiries): void {
        if ($claim()) {
            $expiries[] = $order->expiresMs;
        }
    });
    $operation = signedDestroy($clone);
    app(StartSwampRun::class)->start($operation);
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $firstExpiry = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail()->first_order_expires_ms;
    $horizon = $firstExpiry - ExecutorOrderSigner::MaxExpiryAheadMs + ExecutorJournalLookup::RetentionMs;
    $this->travelTo(Carbon::createFromTimestampMs($horizon - ExecutorOrderSigner::StepExpiresAfterMs + $offset));
    $engine = app(Engine::class);
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    expect($expiries)->toBe($attempts === 2 ? [$firstExpiry, $horizon] : [$firstExpiry]);
    $this->travelTo(Carbon::createFromTimestampMs($horizon + 60_000));
    app(CancelOperationAction::class)->execute($operation);
    app(ExpireOperationAction::class)->execute($operation->refresh());
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    $engine->nudgeIfStalled($operation->refresh());
    $line = ['operation' => $operation->id.':remove', 'action' => 'instance.destroy', 'target' => (string) $clone->id];
    $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$line, 'status' => 'refused', 'reason' => 'expired']]);
    $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$line, 'status' => 'failed', 'code' => 'executor.order_expired']]);

    $step = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail();
    expect($step->resend_at_ms)->toBeNull()->and($step->sends)->toBe($attempts)
        ->and($operation->status)->toBe(OperationStatus::Running)
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $clone->id))
        ->and(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($clone, true)))->toBe('operation.target_busy');
    $engine->onReceipt(['node' => $clone->node_id, 'line' => [...$line, 'status' => 'refused', 'reason' => 'replayed_nonce',
        'first' => [...$line, 'status' => 'failed', 'code' => 'instance.removal_dataset_taken']]]);
    $removal = InstanceRemoval::query()->findOrFail($operation->input['removal_id']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)->and($operation->lock_key)->toBeNull()
        ->and($operation->error_code)->toBe('instance.removal_dataset_taken')->and($operation->failed_step)->toBe('Delete the container')
        ->and($removal->status)->toBe(InstanceRemovalStatus::Failed)->and($removal->failed_step)->toBe(InstanceRemovalStep::RuntimeCleanup)
        ->and(InstanceRemovalReaper::stuck($removal))->toBeTrue()
        ->and($clone->refresh()->status)->toBe(InstanceState::Removing)->and($clone->runtime()->exists())->toBeTrue();
})->with(['last valid expiry' => [0, 2], 'expiry would cross the horizon' => [1, 1], 'journal can have compacted' => [60_000, 1]]);

it('records bounded redacted signed removal diagnostics in the public Operation', function (string $code, string $label, bool $omitted = false): void {
    $clone = destroyClone(destroyDefault());
    $clone->environmentValues()->create(['env_key' => 'CUSTOM_VALUE', 'env_value' => 'disposable-env-sentinel']);
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->once()->andReturn(['instance.destroy']);
    $bus->shouldReceive('publishStart', 'scheduleDeadline')->once();
    $bus->shouldReceive('publishOrder')->once()->andReturnUsing(static fn (int $node, SignedExecutorOrder $order, Closure $claim): bool => $claim());
    $operation = signedDestroy($clone);
    app(StartSwampRun::class)->start($operation);
    $engine = app(Engine::class);
    $engine->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $detail = $omitted ? 'The incomplete native diagnostic line was omitted.' : str_repeat("Ressource occupée\n", 500)
        .'Error: could not clean disposable-env-sentinel at https://user:disposable-url-sentinel@example.test/owned';
    $engine->onReceipt(['node' => $clone->node_id, 'line' => ['operation' => $operation->id.':remove', 'action' => 'instance.destroy',
        'target' => (string) $clone->id, 'status' => 'failed', 'code' => $code, 'detail' => $detail]]);

    $error = OperationData::fromModel($operation->refresh())->error;
    $step = OperationEngineStep::query()->where('operation_id', $operation->id)->firstOrFail();
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->failed_step)->toBe($label)
        ->and($error['stderr_recorded'])->toBeTrue()->and($error['detail'])->toBe($step->error_detail)
        ->and(strlen($error['detail']))->toBeLessThanOrEqual(4096)->and(mb_check_encoding($error['detail'], 'UTF-8'))->toBeTrue()
        ->and($error['detail'])->toContain($omitted ? $detail : 'Error: could not clean [REDACTED] at https://[REDACTED]@example.test/owned')
        ->not->toContain('disposable-env-sentinel', 'disposable-url-sentinel');
})->with([
    'stop' => ['instance.removal_stop_failed', 'Stop the container'],
    'delete' => ['instance.removal_incomplete', 'Delete the container'],
    'dataset refusal' => ['instance.removal_dataset_taken', 'Delete the container'],
    'discard' => ['instance.power_discard_failed', 'Delete the container'],
    'recorded but omitted' => ['instance.removal_incomplete', 'Delete the container', true],
]);

it('does not bind or dispatch signed removal while another Operation holds the Instance lock', function (): void {
    $clone = destroyClone(destroyDefault());
    $lock = OperationStore::lockKey('instance', $clone->id);
    $other = Operation::query()->create(['kind' => 'instance.sleep', 'target_type' => 'instance', 'target_id' => $clone->id,
        'node_id' => $clone->node_id, 'status' => OperationStatus::Running, 'lock_key' => $lock, 'input' => [], 'state' => []]);
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->once()->andReturn(['instance.destroy']);
    $bus->shouldNotReceive('publishStart', 'publishOrder');
    $operation = signedDestroy($clone);

    expect(containerRefusal(fn () => app(StartSwampRun::class)->start($operation)))->toBe('operation.target_busy')
        ->and($other->refresh()->lock_key)->toBe($lock)
        ->and($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->state)->not->toHaveKey('swamp_run_id')
        ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->status)->toBe(InstanceRemovalStatus::Failed)
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('keeps container default destruction unavailable in this clone-only slice', function (bool $force): void {
    $default = destroyDefault();
    $before = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($default, $force)))->toBe('operation.kind_unavailable')
        ->and(Operation::query()->count())->toBe($before)->and($default->fresh()->status)->toBe(InstanceState::Active);
})->with([false, true]);

it('removes a container default only on the operator list and without a copy, with or without an inner volume', function (string $case, string $code, bool $inner): void {
    $default = destroyDefault();
    // A trial default of a nested fleet, made with an inner volume or before it (docs/reference/development-containers.md#destroy).
    $default->runtime()->update(['inner_volume' => $inner ? 'inner-'.$default->id : null]);
    config(['instances.default_destroy_projects' => match ($case) {
        'Project not listed' => [$default->project_id + 1],
        'malformed list' => ['0' => 'x'],
        default => [$default->project_id],
    }]);
    if ($case === 'a copy exists') {
        destroyClone($default);
    }
    $before = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($default->refresh(), $case !== 'no --force')))->toBe($code)
        ->and(Operation::query()->count())->toBe($before)->and($default->fresh()->status)->toBe(InstanceState::Active);
})->with([
    'Project not listed' => ['Project not listed', 'operation.kind_unavailable'],
    'malformed list' => ['malformed list', 'instance.default_destroy_projects_invalid'],
    'a copy exists' => ['a copy exists', 'instance.default_has_copies'],
    'no --force' => ['no --force', 'instance.container_destroy_force_required'],
])->with(['inner volume' => true, 'no inner volume' => false]);

it('keeps a listed nested-fleet default without an inner volume refused while a copy of it exists', function (): void {
    // The shape of the first nested-fleet default: the nested Incus project, no inner volume, and task copies of it.
    $default = destroyDefault();
    // The database keeps a recorded project from changing, so the runtime row is made again with the nested project.
    $row = $default->runtime()->firstOrFail()->getAttributes();
    $default->runtime()->delete();
    unset($row['id']);
    InstanceRuntime::query()->create([...$row, 'incus_project' => InstancePlacement::NestedProject, 'inner_volume' => null, 'idmap_size' => 101000000]);
    config(['instances.default_destroy_projects' => [$default->project_id]]);
    $copy = destroyClone($default->refresh());
    $before = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($default->refresh(), true)))->toBe('instance.default_has_copies')
        ->and(Operation::query()->count())->toBe($before)->and($default->fresh()->status)->toBe(InstanceState::Active)
        ->and($copy->fresh()->status)->toBe(InstanceState::Active)->and($copy->runtime()->value('incus_project'))->toBe(InstancePlacement::NestedProject);
});

it('destroys a listed container default without a copy and frees its records', function (bool $inner): void {
    $default = destroyDefault();
    // A trial default is stopped; the removal does not start it first.
    $default->runtime()->update(['inner_volume' => $inner ? 'inner-'.$default->id : null, 'power_state' => InstanceRuntimePowerState::Stopped]);
    config(['instances.default_destroy_projects' => [$default->project_id]]);

    $operation = app(StartInstanceDestroyAction::class)->execute($default->refresh(), true);
    expect($operation->input['container_destroy'])->toMatchArray(['name' => 'instance-'.$default->id]);
    // Only a default with an inner volume names one, so the run deletes no volume for the other.
    $inner
        ? expect($operation->input['container_destroy']['inner_volume'])->toBe('inner-'.$default->id)
        : expect($operation->input['container_destroy'])->not->toHaveKey('inner_volume');
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'succeeded'));
    expect($default->fresh())->toBeNull()
        ->and(InstanceRuntime::query()->where('instance_id', $default->id)->exists())->toBeFalse();
})->with(['inner volume' => true, 'no inner volume' => false]);

it('destroys an unlisted container default whose create failed and is retained for a retry', function (InstanceState $state): void {
    $default = destroyDefault();
    $default->update(['status' => $state, 'failed_step' => 'container', 'error_code' => 'instance.container_endpoint_unresolved']);
    config(['instances.default_destroy_projects' => []]);
    // The failed create has ended and let go of the Instance.
    Operation::query()->where('lock_key', OperationStore::lockKey('instance', $default->id))->update(['lock_key' => null]);

    $operation = app(StartInstanceDestroyAction::class)->execute($default->refresh(), true);
    expect($operation->input['container_destroy'])->toMatchArray(['name' => 'instance-'.$default->id, 'acl' => 'instance-'.$default->id])
        ->and($default->fresh()->status)->toBe(InstanceState::Removing);
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'succeeded'));
    expect($default->fresh())->toBeNull()
        ->and(InstanceRuntime::query()->where('instance_id', $default->id)->exists())->toBeFalse();
})->with(['reserved' => InstanceState::Reserved, 'source resolved' => InstanceState::SourceResolved]);

it('keeps an unlisted container default refused while a create holds it, also an expired retry whose run is unsettled', function (InstanceState $state, bool $expired): void {
    $default = destroyDefault();
    config(['instances.default_destroy_projects' => []]);
    $default->update(['status' => $state, 'failed_step' => 'container', 'error_code' => 'instance.create_container_failed']);
    // The default's own create has ended and let go of the Instance; its retry now holds it.
    Operation::query()->where('lock_key', OperationStore::lockKey('instance', $default->id))->update(['lock_key' => null]);
    $retry = containerCreate($default->project, $default->node);
    $retry->forceFill(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(), 'state' => [...($retry->state ?? []), 'swamp_run_id' => 'retry-run']])->save();
    expect($retry->target_id)->toBe($default->id)->and($retry->lock_key)->toBe(OperationStore::lockKey('instance', $default->id));
    if ($expired) {
        // The Gateway times the retry out while its run may still go: it keeps the Instance lock until the run settles.
        $this->travel(OperationRunBudgets::seconds($retry) + 60)->seconds();
        app(ExpireOperationAction::class)->execute($retry->refresh());
        expect(ExpireOperationAction::expiredUnsettled($retry->refresh()))->toBeTrue()
            ->and($retry->lock_key)->toBe(OperationStore::lockKey('instance', $default->id));
    }
    $before = Operation::query()->count();
    $cancel = $retry->refresh()->cancel_requested_at?->toIso8601String();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($default->refresh(), true)))->toBe('operation.kind_unavailable')
        ->and(Operation::query()->count())->toBe($before)->and($default->fresh()->status)->toBe($state)
        ->and($retry->refresh()->status)->toBe($expired ? OperationStatus::Failed : OperationStatus::Running)->and($retry->cancel_requested_at?->toIso8601String())->toBe($cancel)
        ->and($retry->lock_key)->toBe(OperationStore::lockKey('instance', $default->id));
})->with(['reserved' => InstanceState::Reserved, 'source resolved' => InstanceState::SourceResolved])
    ->with(['running retry' => false, 'expired unsettled retry' => true]);

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
    expect(InstanceRuntime::query()->where('node_id', $clone->node_id)->where('t3_port', $port)->exists())->toBeFalse();
    // The freed port is not given again at once: the next clone takes the port after it (development-containers.md#prepare-a-node).
    $next = destroyClone($default);
    expect($next->runtime->t3_port)->toBe($port + 1);
    expect($default->runtime()->exists())->toBeTrue();
    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key')->all();
    expect($deleted)->toContain('native-gateway-copy', ($migrated ? 'instance-migrate-model-' : 'instance-create-model-').$create->id)
        ->toContain(($migrated ? 'instance-migrate-processes-' : 'instance-create-processes-').$create->id)
        ->not->toContain('instance-create-model-'.Operation::query()->where('target_id', $default->id)->value('id'));
})->with(['clone' => false, 'migrated checkout' => true]);

it('deletes the clone stdin and stage copies of a create, also a failed or cancelled one that keeps its Instance, at its destroy', function (string $status): void {
    $default = destroyDefault();
    $clone = destroyClone($default);
    // A failed or cancelled create keeps its reserved Instance (retained_for_retry); its execution copies stay until this destroy.
    $create = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $clone->id,
        'node_id' => $clone->node_id, 'status' => $status, 'input' => [], 'state' => []]);
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'succeeded'));

    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key')->all();
    expect($clone->fresh())->toBeNull()
        ->and($deleted)->toContain('instance-create-clone-'.$create->id, 'instance-create-stage-'.$create->id, 'instance-create-launch-'.$create->id, 'instance-create-redact-token-'.$create->id, 'instance-create-redact-model-'.$create->id, 'instance-git-credential-'.$create->id, 'instance-create-model-'.$create->id);
})->with(['failed', 'cancelled', 'succeeded']);

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

it('names the refusal of an owned database server child by the child\'s own code', function (string $dropOut, int $dropExit, string $code, string $step): void {
    $clone = destroyClone(destroyDefault());
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    // The drop exits 1 on a refusal, which the child allows, so the end check is the step that fails.
    $failure = ['summary' => ['failed_steps' => ['Check they are gone', 'Drop owned databases-0']],
        'steps' => ['Drop databases and users' => ['status' => 'succeeded', 'stdout' => $dropOut, 'exit_code' => $dropExit],
            'Check they are gone' => ['status' => 'failed', 'exit_code' => 1], 'Drop owned databases-0' => ['status' => 'failed']]];
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'failed', $failure));
    expect($operation->fresh()->error_code)->toBe($code)->and($operation->fresh()->result['failed_step'] ?? null)->toBe($step)
        ->and($clone->fresh()->status)->toBe(InstanceState::Removing);
})->with([
    'a session blocks the drop' => ["database.in_use\n", 1, 'database.in_use', 'Drop databases and users'],
    'the drop batch fails' => ['', 1, 'database.server_admin_failed', 'Drop databases and users'],
    'a name remains' => ['', 0, 'database.destroy_incomplete', 'Check they are gone'],
]);

it('records the failed container deletion\'s stderr tail from the removal file, redacted and bounded, as recorded stderr', function (): void {
    $clone = destroyClone(destroyDefault());
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    // Swamp keeps no stderr of a failed command step; the phase writes its failed task and stderr tail into remove.json.
    $line = 'Stop and delete the container with its snapshots and volume: non-zero return code: '
        .str_repeat("Error: storage busy\n", 300).'Error: fetch https://deploy:s3cretPassw0rd@git.example.test/x.git failed';
    $result = ['summary' => ['failed_steps' => ['Delete the container']], 'steps' => [
        'Delete the container' => ['status' => 'failed', 'exit_code' => 2],
        'Read container removal' => ['status' => 'succeeded', 'stdout' => json_encode(['error_code' => 'instance.removal_incomplete', 'detail' => $line])]]];
    $terminal = [...destroyTerminal($operation, 'failed', $result),
        'failure' => ['failed_step' => 'Delete the container', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false]];

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, $terminal);

    $error = OperationData::fromModel($operation->fresh())->error;
    expect($error['stderr_recorded'])->toBeTrue()->and($operation->fresh()->failed_step)->toBe('Delete the container')
        ->and(strlen($error['detail']))->toBeLessThanOrEqual(4096)
        ->and($error['detail'])->not->toContain('s3cretPassw0rd')->not->toContain('non-zero return code')
        ->and($error['detail'])->toStartWith("Error: storage busy\n")->toEndWith('git.example.test/x.git failed')->toContain('[REDACTED]');
});

it('records a failed container stop\'s stderr from the removal file, and keeps the removal at the runtime cleanup', function (): void {
    $clone = destroyClone(destroyDefault());
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    // A failed stop skips the deletion; its refusal is in remove.json, the one file "Read container removal" prints.
    $result = ['summary' => ['failed_steps' => ['Stop the container']], 'steps' => [
        'Stop the container' => ['status' => 'failed', 'exit_code' => 2],
        'Delete the container' => ['status' => 'skipped'],
        'Read container removal' => ['status' => 'succeeded', 'stdout' => json_encode(['error_code' => 'instance.removal_incomplete',
            'detail' => 'Stop the container: non-zero return code: Error: Failed shutting down instance'])]]];
    $terminal = [...destroyTerminal($operation, 'failed', $result),
        'failure' => ['failed_step' => 'Stop the container', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false]];

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, $terminal);

    $error = OperationData::fromModel($operation->fresh())->error;
    expect($error['stderr_recorded'])->toBeTrue()->and($error['detail'])->toContain('Error: Failed shutting down instance')
        ->and($operation->fresh()->failed_step)->toBe('Stop the container')
        ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->failed_step)->toBe(InstanceRemovalStep::RuntimeCleanup);
});

it('takes a known refusal code of the container phase from its result file, and keeps any other code generic', function (string $step, string $written, string $code): void {
    $clone = destroyClone(destroyDefault());
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $result = ['summary' => ['failed_steps' => [$step]], 'steps' => [
        $step => ['status' => 'failed', 'exit_code' => 2],
        'Read container removal' => ['status' => 'succeeded', 'stdout' => json_encode(['error_code' => $written,
            'detail' => 'Refuse a name that a dataset holds now: instance.removal_dataset_taken'])]]];
    $terminal = [...destroyTerminal($operation, 'failed', $result),
        'failure' => ['failed_step' => $step, 'detail' => 'Command exited with code 2', 'stderr_recorded' => false]];

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, $terminal);

    expect($operation->fresh()->error_code)->toBe($code)->and($operation->fresh()->failed_step)->toBe($step)
        ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->error_code)->toBe($code);
})->with([
    'taken dataset at the deletion' => ['Delete the container', 'instance.removal_dataset_taken', 'instance.removal_dataset_taken'],
    'taken dataset at the stop' => ['Stop the container', 'instance.removal_dataset_taken', 'instance.removal_dataset_taken'],
    'an unknown code' => ['Delete the container', 'instance.something_else', 'instance.removal_incomplete'],
]);

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

it('deletes the rendered .env copies of the task copy\'s publishes, and no other Instance\'s', function (): void {
    $clone = destroyClone(destroyDefault());
    $mine = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $clone->node_id,
        'status' => 'failed', 'input' => ['instance_id' => $clone->id, 'workspace' => []], 'state' => []]);
    $other = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => 2, 'node_id' => $clone->node_id,
        'status' => 'failed', 'input' => ['instance_id' => $clone->id + 1000, 'workspace' => []], 'state' => []]);
    $operation = app(StartInstanceDestroyAction::class)->execute($clone, true);
    $this->socket->messages = [];

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, destroyTerminal($operation, 'succeeded'));

    $deleted = collect($this->socket->messages)->where('type', 'vault.delete')->pluck('payload.key');
    expect($deleted)->toContain('task-publish-environment-'.$mine->id)
        ->and($deleted)->not->toContain('task-publish-environment-'.$other->id);
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
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
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
    // The clone's completion mints its database copies from the create's plan (NativeInstanceCompleteInput).
    app(NativeDevelopmentInstanceInput::class)->serverDatabase($create, $create->input['direct_provisioning']['server_database']);
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
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
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
    // The clone's completion mints its Valkey user's copies from the create's plan (NativeInstanceCompleteInput).
    app(NativeDevelopmentInstanceInput::class)->serverDatabase($create, $create->input['direct_provisioning']['server_valkey']);
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

it('preserves the registration and storage of a production release before and after its first receipt', function (bool $verified): void {
    $instance = destroyDefault();
    $instance->forceFill(['production_release_started_at' => now(), 'production_release' => $verified
        ? ['live' => str_repeat('a', 40), 'old' => null, 'artifact' => str_repeat('b', 64)] : null])->save();
    $count = Operation::query()->count();
    expect(containerRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($instance, true)))->toBe('operation.kind_unavailable')
        ->and(containerRefusal(fn () => app(StartInstanceUnregisterAction::class)->execute($instance)))->toBe('operation.kind_unavailable')
        ->and(Operation::query()->count())->toBe($count)
        ->and($instance->fresh())->not->toBeNull();
})->with([false, true]);

/** A signed destroy of a clone that owns Route 42 on its Node, with the after-state the admission renders for the engine. */
function routeOwningDestroy(Instance $clone, array $engine = []): Operation
{
    $operation = signedDestroy($clone);
    $input = $operation->input;
    $node = $clone->node_id;
    data_set($input, 'members.0.route_id', 42);
    data_set($input, 'native_destroy.route_mutations.restore.routes', [['route_id' => 42, 'domain' => 'feature.outside.test', 'node_ids' => [$node]]]);
    data_set($input, 'native_destroy.route_mutations.forward', ['routes' => [],
        'publications' => [['node_id' => $node, 'user' => 'orbit']], 'cleanup' => [['node_id' => $node, 'user' => 'orbit', 'scope' => 'app-instance-'.$clone->id]],
        'lan' => [], 'ingress' => [], 'retire_lan' => [], 'retire_ingress' => []]);
    $input['route_engine'] = $engine === [] ? [$node => [
        'caddy' => ['from_sha256' => hash('sha256', "feature.outside.test {\n}\nother.test {\n}\n"), 'content' => "other.test {\n}\n"],
        'pools' => [['version' => '8.4', 'content' => "[other]\n", 'from' => "[feature]\n[other]\n"]],
        'dns' => ['records' => ['from_sha256' => hash('sha256', "a\nb\n"), 'content' => "b\n"], 'catalog' => ['from_sha256' => hash('sha256', "{\"a\":1}\n"), 'content' => "{}\n"]],
    ]] : $engine;
    $operation->input = $input;

    return $operation;
}

it('withdraws an owned private Route before the signed removal, and frees its Nodes in between', function (): void {
    $clone = destroyClone(destroyDefault());
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->andReturn(['instance.destroy', 'route.withdraw_site', 'dns.publish_records']);
    $bus->shouldReceive('nodeFeatures')->andReturn(['dns.guarded_records']);
    $operation = routeOwningDestroy($clone);
    $node = $clone->node_id;
    $flow = app(InstanceDestroyEngineFlow::class);

    expect(array_map(static fn ($step): array => [$step->name, $step->action, $step->after], $flow->steps($operation)))->toBe([
        ["dns-{$node}", 'dns.publish_records', []],
        ["withdraw-{$node}", 'route.withdraw_site', ["dns-{$node}"]],
        ["sibling-{$node}", 'route.probe', ["withdraw-{$node}"]],
        ['release', 'route.release', ["dns-{$node}", "sibling-{$node}"]],
        ['remove', 'instance.destroy', ['release']],
    ])->and(app(EngineKinds::class)->routes($operation))->toBeTrue()
        ->and($flow->input($operation, "withdraw-{$node}", new EngineRun($operation->id, [], 0, null)))->toBe([
            'account' => 'orbit', 'domains' => ['feature.outside.test'], 'leaves' => ['app-instance-'.$clone->id],
            'php' => [['version' => '8.4', 'user' => null, 'content' => ['blob' => ['content' => "[other]\n"]], 'from' => ['blob' => ['content' => "[feature]\n[other]\n"]]]],
            'caddy' => ['from_sha256' => hash('sha256', "feature.outside.test {\n}\nother.test {\n}\n"), 'content' => ['blob' => ['content' => "other.test {\n}\n"]]],
        ])
        ->and($flow->input($operation, "dns-{$node}", new EngineRun($operation->id, [], 0, null))['records'])
        ->toBe(['blob' => ['content' => "b\n"], 'from_sha256' => hash('sha256', "a\nb\n")]);
});

it('keeps the shipped clone removal without a Route as one signed remove step', function (): void {
    $clone = destroyClone(destroyDefault());
    destroyBus();
    $operation = signedDestroy($clone);

    expect(array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->target, $step->after, $step->budgetMs, $step->resendMs],
        app(InstanceDestroyEngineFlow::class)->steps($operation)))->toBe([['remove', $clone->node_id, 'instance.destroy', (string) $clone->id, [], 600_000, 15_000]]);
});

it('removes at once on a retry whose earlier run withdrew the Route', function (): void {
    $clone = destroyClone(destroyDefault());
    destroyBus();
    $operation = routeOwningDestroy($clone);
    $input = $operation->input;
    $input['route_withdrawn'] = true;
    $operation->input = $input;

    expect(array_map(static fn ($step): string => $step->name, app(InstanceDestroyEngineFlow::class)->steps($operation)))->toBe(['remove']);
});

it('leaves a Route-owning removal the engine cannot reproduce to Swamp', function (string $case): void {
    $clone = destroyClone(destroyDefault());
    $bus = destroyBus();
    $bus->shouldReceive('nodeActions')->andReturn(['instance.destroy', 'route.withdraw_site', 'dns.publish_records']);
    $bus->shouldReceive('nodeFeatures')->andReturn($case === 'an older VPN executor' ? [] : ['dns.guarded_records']);
    $operation = routeOwningDestroy($clone, $case === 'a PHP version whose pool empties' ? [$clone->node_id => ['pool_removed' => true]] : []);
    $input = $operation->input;
    match ($case) {
        'no rendered after-state' => $input['route_engine'] = [],
        'a LAN rule' => data_set($input, 'native_destroy.route_mutations.forward.retire_lan', [['node_id' => 3, 'rules' => [['port' => 80, 'ensure' => 'absent']]]]),
        'the Ingress edge' => data_set($input, 'native_destroy.route_mutations.forward.retire_ingress', [['node_id' => 6, 'rules' => [['port' => 443, 'ensure' => 'absent']]]]),
        'an order over the signer\'s limit' => data_set($input, 'native_destroy.route_mutations.restore.routes', array_map(static fn (int $i): array => ['route_id' => 42,
            'domain' => "feature-{$i}-".str_repeat('x', 40).'.outside.test', 'node_ids' => [$clone->node_id]], range(1, 60))),
        default => null,
    };
    $operation->input = $input;

    expect(app(EngineKinds::class)->routes($operation))->toBeFalse();
})->with(['no rendered after-state', 'a LAN rule', 'the Ingress edge', 'a PHP version whose pool empties', 'an older VPN executor', 'an order over the signer\'s limit']);

it('records the withdrawal on the removal at the release step, as the Swamp run\'s release does', function (): void {
    $clone = destroyClone(destroyDefault());
    destroyBus();
    $operation = routeOwningDestroy($clone);
    $operation->save();

    app(InstanceDestroyEngineFlow::class)->local($operation, 'release', new EngineRun($operation->id, [], 0, null));

    expect(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->route_withdrawn_at)->not->toBeNull()
        ->and(json_decode(app(InstanceDestroyEngineFlow::class)->local($operation, 'release', new EngineRun($operation->id, [], 0, null)), true))
        ->toBe(['route_hold_released' => true, 'http_status' => '200']);
});

it('projects a failed withdrawal step as the Swamp run\'s Route withdrawal failure', function (): void {
    $clone = destroyClone(destroyDefault());
    destroyBus();
    $operation = routeOwningDestroy($clone);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => EngineRun::runIdOf($operation->id)]])->save();
    $failed = (new OperationEngineStep)->forceFill(['name' => "withdraw-{$clone->node_id}", 'node_id' => $clone->node_id, 'status' => 'failed', 'error_code' => 'owned_revision_conflict']);

    app(DevelopmentInstanceDestroyProjection::class)->applyEngine($operation, new EngineRun($operation->id, [$failed->name => $failed], 1_800_000_000_000, 1_800_000_001_000));

    $operation->refresh();
    expect($operation->error_code)->toBe('route.withdrawal_failed')
        ->and($operation->result)->toMatchArray(['failed_step' => 'Withdraw the Route', 'withdrawal_error_code' => 'owned_revision_conflict'])
        ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->failed_step)->toBe(InstanceRemovalStep::RouteTargetClear)
        ->and(Instance::query()->find($clone->id))->not->toBeNull();
});

it('renders the whole after-state of a Route-owning clone at admission, without its Route, for the engine', function (): void {
    $clone = destroyClone(destroyDefault());
    $clone->node->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'app', 'node_id' => $clone->node_id, 'project_id' => $clone->project_id, 'domain' => 'feature.outside.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $clone->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    destroyBus()->shouldReceive('nodeActions', 'nodeFeatures')->andReturn([]);
    $operation = signedDestroy($clone);
    $row = $operation->input['route_engine'][$clone->node_id] ?? null;

    expect($row)->toBeArray()
        ->and($row['caddy']['content'])->not->toContain('feature.outside.test')
        ->and($row['dns']['records']['content'])->not->toContain('feature.outside.test')
        ->and(array_map(static fn ($step): string => $step->name, app(InstanceDestroyEngineFlow::class)->steps($operation)))
        ->toBe(["dns-{$clone->node_id}", "withdraw-{$clone->node_id}", "sibling-{$clone->node_id}", 'release', 'remove']);
});

it('keeps the release outcome and the container\'s own failure when the removal fails after a withdrawal', function (bool $released): void {
    $clone = destroyClone(destroyDefault());
    destroyBus();
    $operation = routeOwningDestroy($clone);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => EngineRun::runIdOf($operation->id)]])->save();
    $release = (new OperationEngineStep)->forceFill(['name' => 'release', 'node_id' => 0, 'status' => 'done',
        'output' => json_encode(['route_hold_released' => $released, 'http_status' => $released ? '200' : '000'])]);
    $remove = (new OperationEngineStep)->forceFill(['name' => 'remove', 'node_id' => $clone->node_id, 'status' => 'failed', 'error_code' => 'instance.removal_stop_failed']);

    app(DevelopmentInstanceDestroyProjection::class)->applyEngine($operation, new EngineRun($operation->id, ['release' => $release, 'remove' => $remove], 1_800_000_000_000, 1_800_000_001_000));

    expect($operation->refresh()->result)->toMatchArray(['failed_step' => 'Stop the container', 'error_code' => 'instance.removal_incomplete',
        'route_hold_released' => $released, 'route_hold_release_status' => $released ? 200 : 0])
        ->and($operation->result)->not->toHaveKey('withdrawal_error_code');
})->with(['released' => true, 'refused' => false]);

it('withdraws a Route-owning clone through the engine, keeps it fenced across a lost withdrawal receipt, and removes it once', function (): void {
    $clone = destroyClone(destroyDefault());
    $clone->node->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'app', 'node_id' => $clone->node_id, 'project_id' => $clone->project_id, 'domain' => 'feature.outside.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $clone->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $node = $clone->node_id;
    $sent = [];
    destroyOrderBus(function (string $subject, mixed $payload) use ($node, &$sent): object {
        if (str_starts_with($subject, '$JS.API.DIRECT.GET.KV_NODES.')) {
            return new Payload('{"actions":["instance.destroy","route.withdraw_site","dns.publish_records"],"features":["dns.guarded_records"]}');
        }
        if (str_starts_with($subject, '$JS.API.CONSUMER.CREATE.ORDERS.')) {
            return (object) [];
        }
        if ($subject === "orbit.node.{$node}.orders" && $payload instanceof Payload) {
            $sent[] = json_decode((string) base64_decode(explode('.', $payload->body)[0], true), true, flags: JSON_THROW_ON_ERROR);
        }

        return new Payload('{"seq":1}');
    });
    $operation = signedDestroy($clone);
    app(StartSwampRun::class)->start($operation);
    $engine = app(Engine::class);
    $engine->onEvent('orbit.engine.start', ['operation' => $operation->id]);
    $receipt = static fn (array $order, array $result): array => ['node' => $node, 'line' => ['action' => $order['action'], 'target' => $order['target'],
        'operation' => $order['operation'], ...$result]];

    expect(array_column($sent, 'operation'))->toBe([$operation->id.":dns-{$node}"])
        ->and($sent[0]['input']['records'])->toHaveKeys(['blob', 'from_sha256'])->and($sent[0]['input']['records']['blob'])->toHaveKey('sha256');
    $engine->onReceipt($receipt($sent[0], ['status' => 'applied', 'changes' => 2]));
    expect(array_column($sent, 'operation'))->toBe([$operation->id.":dns-{$node}", $operation->id.":withdraw-{$node}"]);

    // The withdrawal's receipt is lost: the deadline sends the same step again, and the Instance stays fenced meanwhile.
    $this->travel(31)->seconds();
    $engine->onEvent('orbit.engine.deadline', ['operation' => $operation->id]);
    $withdrawals = array_values(array_filter($sent, static fn (array $order): bool => $order['operation'] === $operation->id.":withdraw-{$node}"));
    expect($withdrawals)->toHaveCount(2)->and($withdrawals[1]['nonce'])->not->toBe($withdrawals[0]['nonce'])
        ->and($withdrawals[1]['input'])->toBe($withdrawals[0]['input'])
        ->and($operation->refresh()->lock_key)->toBe(OperationStore::lockKey('instance', $clone->id))
        ->and(array_column($sent, 'action'))->not->toContain('instance.destroy');

    // The Node's journal answers the second send with the stored result of the one execution.
    $engine->onReceipt($receipt($withdrawals[1], ['status' => 'refused', 'reason' => 'replayed_nonce',
        'first' => ['action' => 'route.withdraw_site', 'target' => (string) $node, 'operation' => $operation->id.":withdraw-{$node}", 'status' => 'applied', 'changes' => 3, 'repeat' => true]]));
    $remove = array_values(array_filter($sent, static fn (array $order): bool => $order['action'] === 'instance.destroy'));
    expect($remove)->toHaveCount(1)
        ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->route_withdrawn_at)->not->toBeNull();

    $engine->onReceipt($receipt($remove[0], ['status' => 'done']));
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toMatchArray(['removed' => true, 'route_hold_released' => true])
        ->and(OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', "withdraw-{$node}")->value('sends'))->toBe(2);
});
