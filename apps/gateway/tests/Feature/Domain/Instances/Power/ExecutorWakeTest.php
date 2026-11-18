<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\BroadcastOperationNotice;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Jobs\Operations\DispatchExecutorWake;
use App\Jobs\Operations\ProjectExecutorWake;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;
use Orbit\Extensions\Conn\Domain\ConnLaunchChild;
use Orbit\Extensions\Conn\Domain\ConnLaunchInstances;
use Orbit\Extensions\Conn\Domain\ConnLaunchSettlement;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Psr\Log\NullLogger;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once dirname(__DIR__).'/ContainerInstanceHelpers.php';

/** Real admission/signing/listener, fake publish at the event boundary and a clocked in-memory result queue. */
function executorWakeInstance(): Instance
{
    $test = test();
    Queue::fake();
    Http::preventStrayRequests();
    config(['orbit.executor.wake_enabled' => true, 'orbit.executor.wait_ms' => 500,
        'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $test->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $test->mock(SshExecutor::class)->shouldNotReceive('execute');
    $test->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'stopped']);
    $test->resultQueue = new class implements ExecutorResultQueue
    {
        public float $now = 1000.0;

        /** @var array<string, list<string>> */
        public array $lists = [];

        /** @var list<float> */
        public array $timeouts = [];

        public ?Closure $arriving = null;

        public function push(string $key, string $line, int $expirySeconds): void
        {
            $this->lists[$key][] = $line;
        }

        public function pop(array $keys, float $timeoutSeconds): ?array
        {
            $this->timeouts[] = $timeoutSeconds;
            if ($this->arriving !== null) {
                $arriving = $this->arriving;
                $this->arriving = null;
                $arriving();
            }
            foreach ($keys as $key) {
                if (($this->lists[$key] ?? []) !== []) {
                    return [$key, array_shift($this->lists[$key])];
                }
            }
            $this->now += $timeoutSeconds;

            return null;
        }
    };
    $queue = $test->resultQueue;
    app()->instance(ExecutorResultWait::class, new ExecutorResultWait($queue, static fn (): float => $queue->now));
    $test->results = new ExecutorResults($queue, new NullLogger, app(CommandActivityInputSanitizer::class));
    $test->publisher = new class(app('events'), [ExecutorOrderBroadcast::class]) extends EventFake
    {
        public ?Closure $publishing = null;

        public function dispatch($event, $payload = [], $halt = false)
        {
            if ($event instanceof ExecutorOrderBroadcast && $this->publishing !== null) {
                ($this->publishing)($event);
            }

            return parent::dispatch($event, $payload, $halt);
        }
    };
    Event::swap($test->publisher);

    return $instance;
}

function executorWakeVisit(Instance $instance, string $uri = '/dashboard?tab=1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->withHeader('X-Forwarded-Uri', $uri)
        ->get('/api/v1/runtime-activations/app-instance/'.$instance->id);
}

/** @param array<string, mixed> $line */
function executorWakeReply(Operation $operation, array $line): void
{
    $reply = ExecutorReply::fromEvent(['operation' => (string) $operation->id,
        'line' => json_encode(['operation' => (string) $operation->id, ...$line], JSON_THROW_ON_ERROR)]);
    expect($reply)->toBeInstanceOf(ExecutorReply::class);
    test()->results->receive($operation->node_id, $reply);
}

it('returns 200 for an in-bound wake and records it before signing and publishing', function (string $status): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use ($instance, $status): void {
        $operation = Operation::query()->findOrFail((int) $event->order->operation);
        $payload = json_decode(base64_decode(explode('.', $event->order->wire)[0]), true);
        expect($operation)->kind->toBe('instance.wake')->status->toBe(OperationStatus::Running)
            ->node_id->toBe($instance->node_id)->lock_key->toBe('instance:'.$instance->id);
        expect($payload)->toMatchArray(['action' => 'wake', 'target' => (string) $instance->id,
            'operation' => (string) $operation->id, 'node' => (string) $instance->node_id]);
        $this->resultQueue->arriving = fn () => executorWakeReply($operation, ['status' => $status]);
    };

    executorWakeVisit($instance)->assertOk()->assertHeaderMissing('X-Orbit-Runtime-Activation-State')->assertContent('');

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull()
        ->and($operation->result)->toMatchArray(['power_state' => 'running', 'executor' => ['operation' => (string) $operation->id, 'status' => $status]])
        ->and($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->operationId === $operation->id && $job->queue === 'operations-prompt');
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with(['done', 'partial']);

it('returns 401 pending at the configured bound and later fails without a relay or Python fallback', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.wait_ms' => 200]);

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending')->assertHeader('Retry-After', '1');

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running)->lock_key->toBe('instance:'.$instance->id);
    expect(max($this->resultQueue->timeouts))->toBeLessThanOrEqual(0.2);
    expect($this->resultQueue->now)->toBeLessThanOrEqual(1000.2);
    $this->travel(7)->seconds();
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.wake_timeout')->lock_key->toBeNull();
    executorWakeVisit($instance)->assertStatus(503)->assertHeader('X-Orbit-Runtime-Activation-State', 'failed');
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('returns 401 pending for an unsuccessful result and retains it for the queued failure', function (string $status, array $details, string $code): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => $status, ...$details]);

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running)->executor_result->toMatchArray(['status' => $status, ...$details]);
    $this->travel(7)->seconds();
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe($code)->lock_key->toBeNull();
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with([
    'refused' => ['refused', ['reason' => 'expired'], 'executor.wake_refused'],
    'failed' => ['failed', ['code' => 'restore_failed'], 'executor.wake_failed'],
]);

it('joins two visits to one Operation and publishes only once', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->resultQueue->arriving = fn () => executorWakeReply($operation, ['status' => 'done']);

    executorWakeVisit($instance, '/second')->assertOk();

    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('commits a Conn launch wake before dispatch and shares it with a visitor through cancellation', function (bool $visitorFirst): void {
    $instance = executorWakeInstance();
    $events = new EventFake($this->publisher, [RecordBroadcast::class]);
    Event::swap($events);
    $launch = app(OperationStore::class)->create(OperationKinds::ConnLaunch, ConnLaunchAccess::Target, $instance->id, $instance->node_id,
        ['instance_id' => $instance->id, 'project_id' => $instance->project_id], 'node:'.$instance->node_id, null);
    $wake = DB::transaction(function () use ($instance, $launch): Operation {
        $wake = app(ConnLaunchInstances::class)->wake($launch, $instance);
        Queue::assertNotPushed(DispatchExecutorWake::class);
        $this->publisher->assertNotDispatched(ExecutorOrderBroadcast::class);

        return $wake;
    });
    expect(ConnLaunchChild::Wake->operationId($launch->refresh()))->toBe($wake->id)
        ->and($wake->state['conn_launch_owner'])->toBe($launch->id);
    $job = Queue::pushed(DispatchExecutorWake::class)->sole();
    expect($job->operationId)->toBe($wake->id)->and($job->queue)->toBe('operations-prompt');
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use ($wake, $launch): void {
        expect((int) $event->order->operation)->toBe($wake->id)
            ->and($wake->refresh()->state['conn_launch_owner'])->toBe($launch->id);
        if (($wake->state['wake_shared'] ?? false) === true) {
            executorWakeReply($wake, ['status' => 'done']);
        }
    };
    if (! $visitorFirst) {
        $job->handle(app(ExecutorWake::class));
        $this->resultQueue->arriving = fn () => executorWakeReply($wake, ['status' => 'done']);
    }

    executorWakeVisit($instance)->assertOk();
    $job->handle(app(ExecutorWake::class));
    app(OperationStore::class)->change($launch, ['cancel_requested_at' => now()]);
    app(ConnLaunchSettlement::class)->advance($launch->refresh());

    expect($wake->refresh())->status->toBe(OperationStatus::Succeeded)->cancel_requested_at->toBeNull();
    expect($wake->state['wake_shared'])->toBeTrue()
        ->and($launch->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'instance.sleep')->exists())->toBeFalse();
    $events->assertNotDispatched(RecordBroadcast::class);
    $this->publisher->assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with(['visitor publishes first' => [true], 'worker publishes first' => [false]]);

it('lets a joining visit use the durable result after another waiter consumed its hand-off', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'partial']);
    $this->resultQueue->lists = [];
    $pops = count($this->resultQueue->timeouts);

    executorWakeVisit($instance)->assertOk();

    expect(count($this->resultQueue->timeouts))->toBe($pops);
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
});

it('projects a late successful result without another request and keeps a terminal end', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'done']);
    $this->resultQueue->lists = [];
    $this->travel(7)->seconds();

    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));

    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded)->executor_result->toMatchArray(['status' => 'done']);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
    $sequence = $operation->sequence;
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    expect($operation->refresh()->sequence)->toBe($sequence);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps 401 pending on a publish error and retains the handoff until publication succeeds', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Publish unavailable.');

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->status->toBe(OperationStatus::Running);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->state)->toMatchArray([ExecutorWake::CompletionState => 'pending']);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps the existing wake when the setting is off and requires no signing key', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.wake_enabled' => false, 'orbit.executor.signing_key' => null]);

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->state)->not->toHaveKey(ExecutorWake::State);
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertNotPushed(BroadcastOperationNotice::class);
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('does not enter a nested-fleet clone start or SSH when executor wakes are enabled', function (): void {
    $instance = executorWakeInstance();
    $runtime = $instance->runtime()->firstOrFail();
    $attributes = $runtime->getAttributes();
    $runtime->delete();
    unset($attributes['id']);
    InstanceRuntime::query()->forceCreate([...$attributes, 'incus_project' => InstancePlacement::NestedProject, 'idmap_size' => 101000000]);
    Operation::query()->create(['kind' => 'instance.sleep', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'succeeded', 'input' => [], 'result' => ['sleep' => 'frozen']]);

    executorWakeVisit($instance)->assertUnauthorized();

    expect(Operation::query()->where('kind', 'instance.start')->count())->toBe(0);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps the progress page for a reply released after the wait bound and completes it later', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event): void {
        $operation = Operation::query()->findOrFail((int) $event->order->operation);
        $this->resultQueue->arriving = function () use ($operation): void {
            $this->resultQueue->now += 0.575;
            executorWakeReply($operation, ['status' => 'done']);
        };
    };

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running)->executor_result->toMatchArray(['status' => 'done']);
    $this->travel(7)->seconds();
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('does not overwrite a cancelled wake with a late successful result', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $operation->forceFill(['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'lock_key' => null])->save();
    executorWakeReply($operation, ['status' => 'done']);
    $this->travel(7)->seconds();

    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));

    expect($operation->refresh())->status->toBe(OperationStatus::Cancelled);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('serves a joined request when another waiter consumes and completes the result inside its bound', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->resultQueue->arriving = function () use ($operation): void {
        executorWakeReply($operation, ['status' => 'done']);
        app(ExecutorWake::class)->complete($operation);
        $this->resultQueue->lists = [];
    };

    executorWakeVisit($instance)->assertOk();

    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('returns within 500 ms and queues the slow Conn wake hooks once after success', function (): void {
    $instance = executorWakeInstance();
    app(ExtensionStore::class)->set('conn', true);
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $environment = ConnEnvironment::query()->create([
        'environment_id' => 'wake-environment', 'label' => 'wake', 'url' => 'http://10.253.1.2:3800',
        'node_id' => $instance->node_id, 'admin_session' => 'fixture',
        'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now(),
    ]);
    $environment->forceFill(['instance_id' => $instance->id])->save();
    ConnPairing::query()->create([
        'node_id' => SwampNodeFixture::Id, 'conn_environment_id' => $environment->id, 'held' => true,
        'client_label' => 'wake', 'session' => 'fixture', 'session_expires_at' => now()->addDay(),
    ]);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once()->withArgs(fn (Instance $woken): bool => $woken->is($instance));
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $socket = new class(swampVaultStandIn()) implements WebSocketClient
    {
        public int $puts = 0;

        public function __construct(private WebSocketClient $inner) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->inner->connect($endpoint, $timeoutSeconds);
        }

        public function send(array $message): void
        {
            if ($message['type'] === 'vault.put') {
                $this->puts++;
                usleep(700000);
            }
            $this->inner->send($message);
        }

        public function receive(float $timeoutSeconds): array
        {
            return $this->inner->receive($timeoutSeconds);
        }

        public function close(): void
        {
            $this->inner->close();
        }

        public function isConnected(): bool
        {
            return $this->inner->isConnected();
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);

    $started = hrtime(true);
    $response = executorWakeVisit($instance);
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    $response->assertOk();
    expect($elapsedMs)->toBeLessThan(500);
    expect($socket->puts)->toBe(0);
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    Queue::assertPushed(ProjectExecutorWake::class, fn (ProjectExecutorWake $job): bool => $job->operationId === $operation->id && $job->queue === 'operations-prompt');
    app('request')->setRouteResolver(static fn () => null);
    try {
        $job = Queue::pushed(ProjectExecutorWake::class)->sole();
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        app(ExecutorWake::class)->complete($operation);
    } finally {
        app(SwampConnection::class)->release();
    }
    expect($socket->puts)->toBe(1);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
});

it('releases the activation worker within 500 ms and delivers its slow notices from the queue', function (bool $done): void {
    $instance = executorWakeInstance();
    if ($done) {
        $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    }
    $events = new class($this->publisher, [RecordBroadcast::class]) extends EventFake
    {
        /** @var list<array{type: string, channel: string, data: array<string, mixed>}> */
        public array $notices = [];

        public function dispatch($event, $payload = [], $halt = false)
        {
            if ($event instanceof RecordBroadcast) {
                $this->notices[] = ['type' => $event->type->value, 'channel' => $event->channel, 'data' => $event->data];
                usleep(200000);
            }

            return parent::dispatch($event, $payload, $halt);
        }
    };
    Event::swap($events);

    $started = hrtime(true);
    $response = executorWakeVisit($instance);
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    $response->assertStatus($done ? 200 : 401);
    expect($elapsedMs)->toBeLessThan(500);
    expect($events->notices)->toBe([]);
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    Queue::assertPushed(BroadcastOperationNotice::class, $done ? 5 : 3);
    app('request')->setRouteResolver(static fn () => null);
    foreach (Queue::pushed(BroadcastOperationNotice::class) as $job) {
        app()->call([$job, 'handle']);
    }
    $expected = [
        ['type' => 'operation.created', 'channel' => 'orbit', 'status' => 'queued', 'sequence' => 1],
        ['type' => 'operation.updated', 'channel' => 'operation.'.$operation->id, 'status' => 'running', 'sequence' => 2],
        ['type' => 'operation.updated', 'channel' => 'orbit', 'status' => 'running', 'sequence' => 2],
    ];
    if ($done) {
        $expected[] = ['type' => 'operation.updated', 'channel' => 'operation.'.$operation->id, 'status' => 'succeeded', 'sequence' => 3];
        $expected[] = ['type' => 'operation.updated', 'channel' => 'orbit', 'status' => 'succeeded', 'sequence' => 3];
    }
    expect(array_map(static fn (array $notice): array => ['type' => $notice['type'], 'channel' => $notice['channel'],
        'status' => $notice['data']['status'], 'sequence' => $notice['data']['sequence']], $events->notices))->toBe($expected);
    foreach ($events->notices as $notice) {
        expect($notice['data'])->not->toHaveKeys(['input', 'result', 'executor_result']);
    }
})->with(['success' => true, 'timeout' => false]);

it('retries a failed queued wake hook and skips it after successful delivery', function (): void {
    $instance = executorWakeInstance();
    $calls = 0;
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->twice()->andReturnUsing(function () use (&$calls): void {
        if (++$calls === 1) {
            throw new RuntimeException('Follow-up unavailable.');
        }
    });
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);

    executorWakeVisit($instance)->assertOk();

    Queue::assertPushed(ProjectExecutorWake::class, 1);
    $job = Queue::pushed(ProjectExecutorWake::class)->sole();
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'Follow-up unavailable.');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($calls)->toBe(2);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->status->toBe(OperationStatus::Succeeded);
});

it('preserves one hook handoff when enqueueing terminal notices throws', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'done']);
    $bus = Bus::getFacadeRoot();
    $noticeFailures = 0;
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus, &$noticeFailures) {
        if ($job instanceof BroadcastOperationNotice && $job->notice['status'] === 'succeeded') {
            $noticeFailures++;
            throw new RuntimeException('Injected notice enqueue failure.');
        }

        return $bus->dispatch($job);
    });

    try {
        executorWakeVisit($instance)->assertOk();
    } finally {
        Bus::swap($bus);
    }

    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    expect($noticeFailures)->toBe(2);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    app(ExecutorWake::class)->complete($operation);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
});

it('recovers a recorded but unqueued hook handoff once after the wake succeeded', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'done']);
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus) {
        if ($job instanceof ProjectExecutorWake) {
            throw new RuntimeException('Injected hook enqueue failure.');
        }

        return $bus->dispatch($job);
    });

    try {
        expect(fn () => app(ExecutorWake::class)->complete($operation))->toThrow(RuntimeException::class, 'Injected hook enqueue failure.');
    } finally {
        Bus::swap($bus);
    }

    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    expect($operation->state)->toMatchArray(['wake_executor_follow_up' => 'pending']);
    Queue::assertNotPushed(ProjectExecutorWake::class);
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    app(ExecutorWake::class)->complete($operation);
    expect($operation->refresh()->state)->toMatchArray(['wake_executor_follow_up' => 'queued']);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once();
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $job = Queue::pushed(ProjectExecutorWake::class)->sole();
    // Dispatch must finish before the queued marker commits, even on an after_commit queue connection.
    expect($job->afterCommit)->toBeFalse();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    app(ExecutorWake::class)->complete($operation);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
});

it('rolls back the pending hook handoff together with wake success', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'done']);

    expect(fn () => DB::transaction(function () use ($operation): void {
        app(ExecutorWake::class)->complete($operation);
        throw new RuntimeException('Roll back wake success.');
    }))->toThrow(RuntimeException::class, 'Roll back wake success.');

    expect($operation->refresh())->status->toBe(OperationStatus::Running);
    expect($operation->state)->not->toHaveKey('wake_executor_follow_up');
    Queue::assertNotPushed(ProjectExecutorWake::class);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('recovers hooks in the scheduled pass after all three completion attempts fail without another visit', function (): void {
    $queues = app('queue');
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'done']);
    $operation->forceFill(['started_at' => now()->subSeconds(10)])->save();
    $completion = Queue::pushed(CompleteExecutorWake::class)->sole();
    $completion->delay(null);
    Queue::swap($queues);
    Bus::dispatch($completion);
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus) {
        if ($job instanceof ProjectExecutorWake) {
            throw new RuntimeException('Injected hook enqueue failure.');
        }

        return $bus->dispatch($job);
    });
    Bus::shouldReceive('dispatchNow')->andReturnUsing(fn (...$arguments) => $bus->dispatchNow(...$arguments));
    $attempts = [];
    $worker = app('queue.worker');
    try {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $job = $queues->connection('gateway')->pop('operations-prompt');
            expect($job)->not->toBeNull();
            $attempts[] = $job->attempts();
            try {
                $worker->process('gateway', $job, new WorkerOptions(backoff: 0, sleep: 0, maxTries: 3));
            } catch (RuntimeException $error) {
                expect($error->getMessage())->toBe('Injected hook enqueue failure.');
            }
        }
    } finally {
        Bus::swap($bus);
    }
    expect($attempts)->toBe([1, 2, 3]);
    expect(DB::table('jobs')->count())->toBe(0);
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    expect($operation->state[ExecutorWake::FollowUpState])->toBe('pending');
    $this->travel(31)->seconds();
    Log::spy();

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($operation->refresh()->state[ExecutorWake::FollowUpState])->toBe('queued');
    expect(DB::table('jobs')->count())->toBe(1);
    Log::shouldHaveReceived('info')->with('Executor wake hook handoffs recovered.',
        ['recovered' => 1, 'operation_ids' => [$operation->id]])->once();
    app()->call([new ReconcileSwampOperations, 'handle']);
    app(ExecutorWake::class)->complete($operation);
    expect(DB::table('jobs')->count())->toBe(1);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once()->withArgs(fn (Instance $woken): bool => $woken->is($instance));
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $job = $queues->connection('gateway')->pop('operations-prompt');
    expect($job)->not->toBeNull();
    $worker->process('gateway', $job, new WorkerOptions(sleep: 0));
    expect($operation->refresh()->state)->toMatchArray([ExecutorWake::FollowUpState => 'delivered', 'wake_executor_projected' => true]);
    app()->call([new ProjectExecutorWake($operation->id), 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    expect(DB::table('jobs')->count())->toBe(0);
});

/** @param array<string, mixed> $attributes */
function executorWakePendingHooks(Instance $instance, array $attributes = []): Operation
{
    return Operation::query()->create([
        'kind' => InstanceWake::Kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => OperationStatus::Succeeded, 'input' => [],
        'state' => [ExecutorWake::State => true, ExecutorWake::FollowUpState => 'pending'],
        'started_at' => now()->subMinute(), 'finished_at' => now()->subSeconds(30),
        ...$attributes,
    ]);
}

it('recovers only old succeeded executor handoffs even when new executor wakes are disabled', function (): void {
    $instance = executorWakeInstance();
    $this->freezeTime();
    $eligible = executorWakePendingHooks($instance);
    $older = executorWakePendingHooks($instance, ['finished_at' => now()->subDays(2)]);
    $skipped = [
        executorWakePendingHooks($instance, ['finished_at' => now()->subSeconds(29)]),
        executorWakePendingHooks($instance, ['finished_at' => null]),
        executorWakePendingHooks($instance, ['kind' => 'instance.sleep']),
        executorWakePendingHooks($instance, ['target_type' => 'node']),
        executorWakePendingHooks($instance, ['status' => OperationStatus::Failed]),
        executorWakePendingHooks($instance, ['status' => OperationStatus::Running, 'finished_at' => null]),
        executorWakePendingHooks($instance, ['state' => [ExecutorWake::FollowUpState => 'pending']]),
        executorWakePendingHooks($instance, ['state' => [ExecutorWake::State => true, ExecutorWake::FollowUpState => 'queued']]),
        executorWakePendingHooks($instance, ['state' => [ExecutorWake::State => true, ExecutorWake::FollowUpState => 'delivered']]),
    ];
    $before = array_map(fn (Operation $operation): array => $operation->refresh()->getAttributes(), $skipped);
    config(['orbit.executor.wake_enabled' => false]);
    Log::spy();

    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(2);

    expect(Queue::pushed(ProjectExecutorWake::class)->pluck('operationId')->all())->toBe([$older->id, $eligible->id]);
    expect(array_map(fn (Operation $operation): array => $operation->refresh()->getAttributes(), $skipped))->toBe($before);
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);
    Queue::assertPushed(ProjectExecutorWake::class, 2);
    Log::shouldHaveReceived('info')->with('Executor wake hook handoffs recovered.',
        ['recovered' => 2, 'operation_ids' => [$older->id, $eligible->id]])->once();
});

it('bounds each recovery pass to fifty handoffs in oldest success order', function (): void {
    $instance = executorWakeInstance();
    $this->freezeTime();
    $ids = [];
    for ($age = 30; $age <= 80; $age++) {
        $ids[] = executorWakePendingHooks($instance, ['finished_at' => now()->subSeconds($age)])->id;
    }

    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(50);

    expect(Queue::pushed(ProjectExecutorWake::class)->pluck('operationId')->all())->toBe(array_slice(array_reverse($ids), 0, 50));
    expect(Operation::query()->findOrFail($ids[0])->state[ExecutorWake::FollowUpState])->toBe('pending');
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(1);
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);
    Queue::assertPushed(ProjectExecutorWake::class, 51);
});

it('does not duplicate a handoff claimed after sweep selection by another sweep or late completion', function (string $competitor): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingHooks($instance, ['finished_at' => now()->subMinute()]);
    $raced = false;
    DB::listen(function (QueryExecuted $query) use ($operation, $competitor, &$raced): void {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, ExecutorWake::FollowUpState)) {
            $raced = true;
            if ($competitor === 'sweep') {
                expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(1);
            } else {
                app(ExecutorWake::class)->complete($operation);
            }
        }
    });

    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);

    expect($raced)->toBeTrue();
    expect($operation->refresh()->state[ExecutorWake::FollowUpState])->toBe('queued');
    app(ExecutorWake::class)->complete($operation);
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
})->with(['sweep', 'completion']);

it('stops recovery on a queue failure and still finishes native reconciliation before retrying next pass', function (): void {
    $instance = executorWakeInstance();
    $pending = [executorWakePendingHooks($instance, ['finished_at' => now()->subMinute()]), executorWakePendingHooks($instance)];
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->once()->with(Mockery::type(ProjectExecutorWake::class))
        ->andThrow(new RuntimeException('Injected hook enqueue failure.'));
    Log::spy();

    try {
        app()->call([new ReconcileSwampOperations, 'handle']);
    } finally {
        Bus::swap($bus);
    }

    foreach ($pending as $operation) {
        expect($operation->refresh()->state[ExecutorWake::FollowUpState])->toBe('pending');
    }
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Log::shouldHaveReceived('warning')->with('An executor wake hook handoff could not be recovered; the next pass tries again.',
        ['operation_id' => $pending[0]->id, 'exception_type' => RuntimeException::class])->once();
    Log::shouldHaveReceived('info')->with('Operation reconcile pass ran.', Mockery::type('array'))->once();
    app()->call([new ReconcileSwampOperations, 'handle']);
    Queue::assertPushed(ProjectExecutorWake::class, 2);
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);
});

it('keeps an in-bound success authorized when its durable hook handoff cannot enqueue', function (string $status, bool $failCompletion): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => $status]);
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus, $failCompletion) {
        if ($failCompletion && $job instanceof CompleteExecutorWake) {
            throw new RuntimeException('Injected completion enqueue failure.');
        }
        if ($job instanceof ProjectExecutorWake) {
            throw new RuntimeException('Injected hook enqueue failure.');
        }

        return $bus->dispatch($job);
    });
    Log::spy();
    $started = hrtime(true);
    try {
        $response = executorWakeVisit($instance);
    } finally {
        Bus::swap($bus);
    }
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    $response->assertOk()->assertHeaderMissing('X-Orbit-Runtime-Activation-State');
    expect($elapsedMs)->toBeLessThan(500);
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Succeeded);
    expect($operation->state[ExecutorWake::FollowUpState])->toBe('pending');
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Log::shouldHaveReceived('warning')->with('The executor wake succeeded but its follow-up could not be queued; the minute sweep retries.',
        ['operation_id' => $operation->id, 'exception_type' => RuntimeException::class])->once();
    $this->travel(31)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once();
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $job = Queue::pushed(ProjectExecutorWake::class)->sole();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($operation->refresh()->state[ExecutorWake::FollowUpState])->toBe('delivered');
})->with(['done' => ['done', false], 'partial' => ['partial', false],
    'done and no completion enqueue' => ['done', true], 'partial and no completion enqueue' => ['partial', true]]);

it('keeps the progress page and recovers a failed initial completion enqueue without another visit', function (bool $publish, bool $reply): void {
    $instance = executorWakeInstance();
    if (! $publish) {
        $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');
    }
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus) {
        if ($job instanceof CompleteExecutorWake) {
            throw new RuntimeException('Injected completion enqueue failure.');
        }

        return $bus->dispatch($job);
    });
    Log::spy();
    try {
        $response = executorWakeVisit($instance);
    } finally {
        Bus::swap($bus);
    }

    $response->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running);
    expect($operation->state['wake_executor_completion'])->toBe('pending');
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, $publish ? 1 : 0);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    if ($publish) {
        Log::shouldHaveReceived('warning')->with('The executor wake completion could not be queued; the minute sweep retries.',
            ['operation_id' => $operation->id, 'exception_type' => RuntimeException::class])->once();
    } else {
        $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    }
    if ($reply) {
        executorWakeReply($operation, ['status' => 'done']);
    }
    $this->travel(7)->seconds();
    config(['orbit.executor.wake_enabled' => false]);
    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);

    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    expect($operation->refresh()->state['wake_executor_completion'])->toBe('queued');
    $job = Queue::pushed(CompleteExecutorWake::class)->sole();
    expect($job->delay->isPast())->toBe($publish);
    expect($job->afterCommit)->toBeFalse();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($operation->refresh()->status)->toBe(($reply || ! $publish) ? OperationStatus::Succeeded : OperationStatus::Failed);
    expect($operation->error_code)->toBe(($reply || ! $publish) ? null : 'executor.wake_timeout');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    Queue::assertPushed(ProjectExecutorWake::class, ($reply || ! $publish) ? 1 : 0);
})->with(['no order' => [false, false], 'no result' => [true, false], 'late success' => [true, true]]);

function executorWakePendingCompletion(Instance $instance, int $age = 6): Operation
{
    return executorWakePendingHooks($instance, [
        'status' => OperationStatus::Running, 'started_at' => now()->subSeconds($age), 'finished_at' => null,
        'state' => [ExecutorWake::State => true, ExecutorWake::CompletionState => 'pending'],
    ]);
}

it('recovers completion handoffs only after six seconds and at most fifty oldest running wakes per pass', function (): void {
    $instance = executorWakeInstance();
    $this->freezeTime();
    $young = executorWakePendingCompletion($instance, 5);
    $closed = executorWakePendingCompletion($instance);
    $closed->update(['status' => OperationStatus::Cancelled]);
    $queued = executorWakePendingCompletion($instance);
    $queued->update(['state' => [ExecutorWake::State => true, ExecutorWake::CompletionState => 'queued']]);
    $native = executorWakePendingCompletion($instance);
    $native->update(['state' => [ExecutorWake::CompletionState => 'pending']]);
    $ids = [];
    for ($age = 6; $age <= 56; $age++) {
        $ids[] = executorWakePendingCompletion($instance, $age)->id;
    }
    $oldest = executorWakePendingCompletion($instance, 86400);
    config(['orbit.executor.wake_enabled' => false]);
    Log::spy();

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(50);

    $expected = [$oldest->id, ...array_slice(array_reverse($ids), 0, 49)];
    expect(Queue::pushed(CompleteExecutorWake::class)->pluck('operationId')->all())->toBe($expected);
    Log::shouldHaveReceived('info')->with('Executor wake completion handoffs recovered.', ['recovered' => 50, 'operation_ids' => $expected])->once();
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(2);
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
    Queue::assertPushed(CompleteExecutorWake::class, 52);
    expect($young->refresh()->state[ExecutorWake::CompletionState])->toBe('pending');
    expect($closed->refresh()->state[ExecutorWake::CompletionState])->toBe('pending');
    expect($native->refresh()->state[ExecutorWake::CompletionState])->toBe('pending');
    $this->travel(1)->seconds();
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
    Queue::assertPushed(CompleteExecutorWake::class, 53);
});

it('claims one completion handoff when two sweeps select the same pending wake', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, ExecutorWake::CompletionState)) {
            $raced = true;
            expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
        }
    });

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);

    expect($raced)->toBeTrue();
    expect($operation->refresh()->state[ExecutorWake::CompletionState])->toBe('queued');
    Queue::assertPushed(CompleteExecutorWake::class, 1);
});

it('retains pending completion on sweep enqueue failure while hook and native reconciliation continue', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $hook = executorWakePendingHooks($instance, ['finished_at' => now()->subMinute()]);
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus) {
        if ($job instanceof CompleteExecutorWake) {
            throw new RuntimeException('Injected completion enqueue failure.');
        }

        return $bus->dispatch($job);
    });
    Log::spy();
    try {
        app()->call([new ReconcileSwampOperations, 'handle']);
    } finally {
        Bus::swap($bus);
    }

    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    expect($operation->refresh()->state[ExecutorWake::CompletionState])->toBe('pending');
    expect($hook->refresh()->state[ExecutorWake::FollowUpState])->toBe('queued');
    Log::shouldHaveReceived('warning')->with('An executor wake completion handoff could not be recovered; the next pass tries again.',
        ['operation_id' => $operation->id, 'exception_type' => RuntimeException::class])->once();
    Log::shouldHaveReceived('info')->with('Operation reconcile pass ran.', Mockery::type('array'))->once();
    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
});

it('publishes the same missing wake order once before recovering completion across sweeps and a late visit', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus) {
        if ($job instanceof CompleteExecutorWake) {
            throw new RuntimeException('Injected completion enqueue failure.');
        }

        return $bus->dispatch($job);
    });
    try {
        executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');
    } finally {
        Bus::swap($bus);
    }
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation->state[ExecutorWake::CompletionState])->toBe('pending');
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    $published = [];
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use (&$published): void {
        $published[] = (int) $event->order->operation;
        executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    };
    $this->travel(7)->seconds();

    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    executorWakeVisit($instance)->assertOk();

    expect($published)->toBe([$operation->id]);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    $job = Queue::pushed(CompleteExecutorWake::class)->sole();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('keeps a recovered publication receipt when enqueueing fails and retries only completion', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->travel(7)->seconds();
    $this->publisher->publishing = null;
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($bus) {
        if ($job instanceof CompleteExecutorWake) {
            throw new RuntimeException('Injected completion enqueue failure.');
        }

        return $bus->dispatch($job);
    });
    try {
        app()->call([new ReconcileSwampOperations, 'handle']);
    } finally {
        Bus::swap($bus);
    }

    expect($operation->refresh()->state)->toMatchArray([ExecutorWake::CompletionState => 'pending']);
    expect($operation->state[ExecutorWake::PublishedAt])->toBeInt();
    $publishedAt = $operation->state[ExecutorWake::PublishedAt];
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    $this->travel(2)->seconds();
    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    executorWakeVisit($instance)->assertUnauthorized();
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect($operation->refresh()->state[ExecutorWake::PublishedAt])->toBe($publishedAt);
    $job = Queue::pushed(CompleteExecutorWake::class)->sole();
    expect($job->delay->getTimestampMs())->toBe($publishedAt + 6000);
    executorWakeReply($operation, ['status' => 'done']);
    app()->call([$job, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('leaves a missing publication pending on sweep failure and recovers after publishing returns', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');

    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($operation->refresh()->state)->not->toHaveKey(ExecutorWake::PublishedAt);
    expect($operation->state[ExecutorWake::CompletionState])->toBe('pending');
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    $this->publisher->publishing = null;
    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);
    executorWakeVisit($instance)->assertUnauthorized();
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    executorWakeReply($operation, ['status' => 'partial']);
    app()->call([Queue::pushed(CompleteExecutorWake::class)->sole(), 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('does not publish twice when a sweep and visit race the publishing handoff and holds no database writer during publish', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $baseline = DB::transactionLevel();
    $raced = false;
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use (&$raced, $baseline, $instance): void {
        expect($raced)->toBeFalse();
        $raced = true;
        expect(DB::transactionLevel())->toBe($baseline);
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
        $started = hrtime(true);
        executorWakeVisit($instance)->assertUnauthorized();
        expect((hrtime(true) - $started) / 1e6)->toBeLessThan(500);
        Queue::assertNotPushed(CompleteExecutorWake::class);
        executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    };

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);

    expect($raced)->toBeTrue();
    expect($operation->refresh()->state)->toHaveKey(ExecutorWake::PublishedAt);
    executorWakeVisit($instance)->assertOk();
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('lets a late visit recover an unpublished order and leaves no duplicate work for the sweeps', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');
    executorWakeVisit($instance)->assertUnauthorized();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->travel(7)->seconds();
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);

    executorWakeVisit($instance)->assertOk();
    app()->call([new ReconcileSwampOperations, 'handle']);
    app()->call([new ReconcileSwampOperations, 'handle']);

    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->id->toBe($operation->id)->status->toBe(OperationStatus::Succeeded);
});

it('gives a recovered publication its six-second result window before timing out', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 60);
    $this->freezeTime();

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);

    $job = Queue::pushed(CompleteExecutorWake::class)->sole()->withFakeQueueInteractions();
    expect($job->delay->getTimestampMs())->toBe(now()->addSeconds(6)->getTimestampMs());
    app()->call([$job, 'handle']);
    $job->assertReleased(1);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
    $this->travel(7)->seconds();
    app()->call([$job, 'handle']);
    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.wake_timeout');
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
});

it('does not wait on a held publication lock and recovers after the lost publisher lease ends', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $this->freezeTime();
    $lock = Cache::lock('orbit:executor-wake-publish:'.$operation->id, 30);
    expect($lock->get())->toBeTrue();
    try {
        $started = hrtime(true);
        executorWakeVisit($instance)->assertUnauthorized();
        expect((hrtime(true) - $started) / 1e6)->toBeLessThan(500);
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
        Event::assertNotDispatched(ExecutorOrderBroadcast::class);
        Queue::assertNotPushed(CompleteExecutorWake::class);
        $this->travel(31)->seconds();
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
    } finally {
        $lock->release();
    }
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
});

it('keeps the first publication receipt if an old publisher resumes after its lease expires', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $first = true;
    $recordedAt = null;
    $this->publisher->publishing = function () use ($operation, &$first, &$recordedAt): void {
        if ($first) {
            $first = false;
            $this->travel(31)->seconds();
            expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
            $recordedAt = $operation->refresh()->state[ExecutorWake::PublishedAt];
            $this->travel(1)->seconds();
        }
    };

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);

    expect($operation->refresh()->state[ExecutorWake::PublishedAt])->toBe($recordedAt);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect(Queue::pushed(CompleteExecutorWake::class)->sole()->delay->getTimestampMs())->toBe($recordedAt + 6000);
    expect(array_map(fn (array $arguments): string => $arguments[0]->order->operation,
        Event::dispatched(ExecutorOrderBroadcast::class)->all()))->toBe([(string) $operation->id, (string) $operation->id]);
    executorWakeReply($operation, ['status' => 'done']);
    app()->call([Queue::pushed(CompleteExecutorWake::class)->sole(), 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});
