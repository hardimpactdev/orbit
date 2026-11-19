<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\ExecutorWakeBudget;
use App\Domain\Instances\Power\ExecutorWakeNotices;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Executor\ExecutorWakeLock;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\EventFake;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;
use Orbit\Extensions\Conn\Domain\ConnLaunchChild;
use Orbit\Extensions\Conn\Domain\ConnLaunchInstances;
use Orbit\Extensions\Conn\Domain\ConnLaunchSettlement;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ExecutorWakeTestHelpers.php';

it('returns 307 to the visit URI for an in-bound wake and records it before signing and publishing', function (string $status): void {
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

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1')->assertContent('');

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull()
        ->and($operation->result)->toMatchArray(['power_state' => 'running', 'executor' => $operation->executor_result])
        ->and($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with(['done', 'partial']);

it('holds a visit until its deadline, answers still waking, later fails without a relay or Python fallback, and wakes again on a reload', function (): void {
    $instance = executorWakeInstance();

    executorWakeVisit($instance)->assertServiceUnavailable()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent("The Instance is still waking. Reload the page in a moment.\n");

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running)->lock_key->toBe('instance:'.$instance->id);
    expect($this->resultQueue->now)->toBeLessThanOrEqual(1000.3);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    executorWakeSweep();
    $this->travel(7)->seconds();
    Queue::pushed(CompleteExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.wake_timeout')->lock_key->toBeNull();
    executorWakeVisit($instance)->assertServiceUnavailable()->assertSee('The Instance is still waking.');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(2);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 2);
    Queue::assertPushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->operationId === $operation->id && $job->queue === 'operations-prompt');
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('answers an unsuccessful result with its failure and retains it for the queued failure', function (string $status, array $details, string $code, string $shown): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => $status, ...$details]);

    executorWakeVisit($instance)->assertServiceUnavailable()->assertContent("The wake failed ({$shown}). Reload the page to try again.\n");

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running)->executor_result->toMatchArray(['status' => $status, ...$details]);
    $this->travel(7)->seconds();
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe($code)->lock_key->toBeNull();
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with([
    'refused' => ['refused', ['reason' => 'expired'], 'executor.wake_refused', 'executor.order_refused'],
    'failed' => ['failed', ['code' => 'restore_failed'], 'executor.wake_failed', 'restore_failed'],
]);

it('ends a held visit with a plain 503 as soon as a failed receipt arrives, not at the deadline', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.visit_wait_seconds' => 3.0]);
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event): void {
        $operation = Operation::query()->findOrFail((int) $event->order->operation);
        $this->resultQueue->arriving = fn () => executorWakeReply($operation, ['status' => 'failed', 'code' => 'restore_failed']);
    };

    $started = hrtime(true);
    $response = executorWakeVisit($instance);
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    $response->assertServiceUnavailable()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent("The wake failed (restore_failed). Reload the page to try again.\n");
    expect($elapsedMs)->toBeLessThan(1000);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->status->toBe(OperationStatus::Running)
        ->executor_result->toMatchArray(['status' => 'failed', 'code' => 'restore_failed']);
});

it('joins two visits to one Operation and publishes only once', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable()->assertSee('The Instance is still waking.');
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->resultQueue->arriving = fn () => executorWakeReply($operation, ['status' => 'done']);

    executorWakeVisit($instance, '/second')->assertStatus(307)->assertHeader('Location', '/second');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(CompleteExecutorWake::class);
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

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
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
    Queue::assertPushed(CompleteExecutorWake::class, $visitorFirst ? 0 : 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with(['visitor publishes first' => [true], 'worker publishes first' => [false]]);

it('honors Conn cancellation before a publisher claims an unshared wake', function (bool $shared): void {
    $instance = executorWakeInstance();
    $launch = app(OperationStore::class)->create(OperationKinds::ConnLaunch, ConnLaunchAccess::Target, $instance->id, $instance->node_id,
        ['instance_id' => $instance->id, 'project_id' => $instance->project_id], 'node:'.$instance->node_id, null);
    $wake = app(ConnLaunchInstances::class)->wake($launch, $instance);
    expect($wake->refresh())->status->toBe(OperationStatus::Queued)->attempts->toBe(0);
    $publisher = Queue::pushed(DispatchExecutorWake::class)->sole();

    app(ExecutorWakeLock::class)->run('publish', $wake->id, function () use ($instance, $launch, $wake, $shared): void {
        if ($shared) {
            executorWakeVisit($instance)->assertServiceUnavailable();
            expect($wake->refresh()->state['wake_shared'])->toBeTrue();
        }
        app(OperationStore::class)->change($launch, ['cancel_requested_at' => now()]);
        app(ConnLaunchSettlement::class)->advance($launch->refresh());
        expect($wake->refresh())->status->toBe($shared ? OperationStatus::Queued : OperationStatus::Cancelled)->attempts->toBe(0);
        Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    });

    $publisher->handle(app(ExecutorWake::class));
    $publisher->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe($shared ? OperationStatus::Running : OperationStatus::Cancelled)->attempts->toBe($shared ? 1 : 0);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, $shared ? 1 : 0);
    Queue::assertPushed(CompleteExecutorWake::class, $shared ? 1 : 0);
})->with(['unshared' => [false], 'shared' => [true]]);

it('lets a joining visit use the durable result after another waiter consumed its hand-off', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'partial']);
    $this->resultQueue->lists = [];
    $pops = count($this->resultQueue->timeouts);

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');

    expect(count($this->resultQueue->timeouts))->toBe($pops);
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(CompleteExecutorWake::class);
});

it('projects a late successful result without another request and keeps a terminal end', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable();
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
    executorWakeSweep();
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('answers still waking on a publish error and retains the handoff until publication succeeds', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Publish unavailable.');

    executorWakeVisit($instance)->assertServiceUnavailable();

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->status->toBe(OperationStatus::Running);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->state)->toMatchArray([ExecutorWake::CompletionState => 'pending']);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps the existing wake when the setting is off and requires no signing key', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.wake_enabled' => false, 'orbit.executor.signing_key' => null]);

    executorWakeVisit($instance)->assertServiceUnavailable();

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->state)->not->toHaveKey(ExecutorWake::State);
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertNotPushed(BroadcastOperationNotice::class);
    Queue::assertNotPushed(ProjectExecutorWake::class);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('does not enter a nested-fleet clone start or SSH for a recorded executor freeze', function (): void {
    $instance = executorWakeInstance();
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $runtime = $instance->runtime()->firstOrFail();
    $attributes = $runtime->getAttributes();
    $runtime->delete();
    unset($attributes['id']);
    InstanceRuntime::query()->forceCreate([...$attributes, 'incus_project' => InstancePlacement::NestedProject, 'idmap_size' => 101000000]);
    Operation::query()->create(['kind' => 'instance.sleep', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'succeeded', 'input' => ['signed_power' => true], 'result' => ['sleep' => 'frozen']]);

    executorWakeVisit($instance)->assertServiceUnavailable();

    expect(Operation::query()->where('kind', 'instance.start')->count())->toBe(0);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('answers still waking for a reply released after the visit deadline and completes it later', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event): void {
        $operation = Operation::query()->findOrFail((int) $event->order->operation);
        $this->resultQueue->arriving = function () use ($operation): void {
            $this->resultQueue->now += 0.575;
            executorWakeReply($operation, ['status' => 'done']);
        };
    };

    executorWakeVisit($instance)->assertServiceUnavailable();

    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running)->executor_result->toMatchArray(['status' => 'done']);
    $this->travel(7)->seconds();
    (new CompleteExecutorWake($operation->id))->handle(app(ExecutorWake::class));
    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('does not overwrite a cancelled wake with a late successful result', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable();
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
    executorWakeVisit($instance)->assertServiceUnavailable();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->resultQueue->arriving = function () use ($operation): void {
        executorWakeReply($operation, ['status' => 'done']);
        app(ExecutorWake::class)->complete($operation);
        $this->resultQueue->lists = [];
    };

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('checks the joined result receipt rather than a same-second finished_at', function (bool $timely): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->travelTo(Carbon::parse('2026-11-18 10:00:00.100000 UTC'));
    $this->resultQueue->arriving = function () use ($operation, $timely): void {
        $this->travel($timely ? 50 : 101)->milliseconds();
        executorWakeReply($operation, ['status' => 'done']);
        app(ExecutorWake::class)->complete($operation);
        $this->resultQueue->lists = [];
    };

    expect(app(ExecutorWake::class)->request($operation, new ExecutorWakeBudget(0.1)))->toBe($timely);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->finished_at->format('u'))->toBe('000000')
        ->and($operation->executor_result_received_us % 1_000_000)->toBe($timely ? 150_000 : 201_000);
})->with(['timely' => [true], 'late in the same second' => [false]]);

it('waits past a bare nonce replay for its authoritative wake result', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event): void {
        $operation = Operation::query()->findOrFail((int) $event->order->operation);
        executorWakeReply($operation, ['status' => 'refused', 'reason' => 'replayed_nonce', 'first' => null]);
        expect($operation->refresh()->executor_result)->toBeNull();
        $this->resultQueue->arriving = fn () => executorWakeReply($operation, ['status' => 'done']);
    };

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->executor_result['status'])->toBe('done');
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

    $response->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
    expect($elapsedMs)->toBeLessThan(500);
    expect($socket->puts)->toBe(0);
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeSweep();
    Queue::assertPushed(ProjectExecutorWake::class, fn (ProjectExecutorWake $job): bool => $job->operationId === $operation->id && $job->queue === 'operations-prompt');
    app('request')->setRouteResolver(static fn () => null);
    try {
        $job = Queue::pushed(ProjectExecutorWake::class)->sole();
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        app(ExecutorWake::class)->complete($operation);
        expect($socket->puts)->toBe(0);
        $hold = Operation::query()->where('kind', 'conn.session_hold')->sole();
        app(DirectOperation::class)->workflowInputs($hold);
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

    $response->assertStatus($done ? 307 : 503);
    expect($elapsedMs)->toBeLessThan(500);
    expect($events->notices)->toBe([]);
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    Queue::assertNotPushed(BroadcastOperationNotice::class);
    app(ExecutorWakeNotices::class)->relay();
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

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
    executorWakeSweep();

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
    executorWakeVisit($instance)->assertServiceUnavailable();
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
        executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
        expect($noticeFailures)->toBe(0);
        executorWakeSweep();
    } finally {
        Bus::swap($bus);
    }

    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    expect($noticeFailures)->toBe(1);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    executorWakeSweep();
    expect(Queue::pushed(BroadcastOperationNotice::class)->filter(fn ($job): bool => $job->notice['status'] === 'succeeded'))->toHaveCount(2);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
});

it('recovers a recorded but unqueued hook handoff once after the wake succeeded', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable();
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
        expect(app(ExecutorWake::class)->complete($operation))->toBeTrue();
        expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);
    } finally {
        Bus::swap($bus);
    }

    expect($operation->refresh())->status->toBe(OperationStatus::Succeeded);
    expect($operation->state)->toMatchArray(['wake_executor_follow_up' => 'pending']);
    Queue::assertNotPushed(ProjectExecutorWake::class);
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(1);
    expect($operation->refresh()->state)->toMatchArray(['wake_executor_follow_up' => 'queued']);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once();
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $job = Queue::pushed(ProjectExecutorWake::class)->sole();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    app(ExecutorWake::class)->complete($operation);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
});

it('rolls back the pending hook handoff together with wake success', function (): void {
    $instance = executorWakeInstance();
    executorWakeVisit($instance)->assertServiceUnavailable();
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

it('reclaims an actually dequeued and exhausted handoff without another visit', function (string $handoff): void {
    $queues = app('queue');
    $instance = executorWakeInstance();
    $this->freezeTime();
    executorWakeVisit($instance)->assertServiceUnavailable();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    executorWakeReply($operation, ['status' => 'done']);
    $wake = app(ExecutorWake::class);
    Queue::swap($queues);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $allowWork = false;
    $hooks = 0;
    $contribution->shouldReceive('projectWake')->andReturnUsing(function () use ($handoff, &$allowWork, &$hooks): void {
        if ($handoff === 'hook' && ! $allowWork) {
            throw new RuntimeException('Injected worker failure.');
        }
        $hooks++;
    });
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    if ($handoff === 'completion') {
        Operation::saving(function (Operation $changing) use ($operation, &$allowWork): void {
            if (! $allowWork && $changing->is($operation) && $changing->status === OperationStatus::Succeeded) {
                throw new RuntimeException('Injected worker failure.');
            }
        });
        $recover = $wake->recoverPendingCompletions(...);
        $state = ExecutorWake::CompletionState;
    } else {
        $wake->complete($operation);
        $recover = $wake->recoverPendingHooks(...);
        $state = ExecutorWake::FollowUpState;
    }
    expect($recover())->toBe(1);
    $this->travel(7)->seconds();
    expect(DB::table('jobs')->count())->toBe(1);
    expect((int) DB::table('jobs')->value('available_at'))->toBeLessThanOrEqual(now()->timestamp);

    $attempts = [];
    $worker = app('queue.worker');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $job = $queues->connection('gateway')->pop('operations-prompt');
        expect($job)->not->toBeNull();
        $attempts[] = $job->attempts();
        expect(fn () => $worker->process('gateway', $job, new WorkerOptions(backoff: 0, sleep: 0, maxTries: 3)))
            ->toThrow(RuntimeException::class, 'Injected worker failure.');
        $next = DB::table('jobs')->value('available_at');
        if ($next !== null) {
            $this->travelTo(Carbon::createFromTimestamp((int) $next + 1));
        }
    }
    expect($attempts)->toBe([1, 2, 3]);
    expect(DB::table('jobs')->count())->toBe(0);
    expect($operation->refresh()->state[$state])->toBe('queued')->and($operation->state['wake_executor_projected'] ?? false)->toBeFalse();
    expect($recover())->toBe(0);
    $this->travel(ExecutorWake::HandoffLeaseSeconds)->seconds();

    $allowWork = true;
    expect($recover())->toBe(1)->and($recover())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
    $job = $queues->connection('gateway')->pop('operations-prompt');
    expect($job)->not->toBeNull();
    $worker->process('gateway', $job, new WorkerOptions(sleep: 0));
    expect(DB::table('jobs')->count())->toBe(0);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    if ($handoff === 'hook') {
        expect($operation->state)->toMatchArray([ExecutorWake::FollowUpState => 'delivered', 'wake_executor_projected' => true]);
        app()->call([new ProjectExecutorWake($operation->id), 'handle']);
        expect($hooks)->toBe(1);
    }
    expect($recover())->toBe(0);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
})->with(['completion', 'hook']);

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

it('recovers only finished executor handoffs even when new executor wakes are disabled', function (): void {
    $instance = executorWakeInstance();
    $this->freezeTime();
    $eligible = executorWakePendingHooks($instance);
    $older = executorWakePendingHooks($instance, ['finished_at' => now()->subDays(2)]);
    $skipped = [
        executorWakePendingHooks($instance, ['finished_at' => null]),
        executorWakePendingHooks($instance, ['kind' => 'instance.sleep']),
        executorWakePendingHooks($instance, ['target_type' => 'node']),
        executorWakePendingHooks($instance, ['status' => OperationStatus::Failed]),
        executorWakePendingHooks($instance, ['status' => OperationStatus::Running, 'finished_at' => null]),
        executorWakePendingHooks($instance, ['state' => [ExecutorWake::FollowUpState => 'pending']]),
        executorWakePendingHooks($instance, ['state' => [ExecutorWake::State => true, ExecutorWake::FollowUpState => 'queued', ExecutorWake::FollowUpState.'_until' => now()->timestamp + 1]]),
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

    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe($competitor === 'sweep' ? 0 : 1);

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
        executorWakeSweep();
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
    executorWakeSweep();
    Queue::assertPushed(ProjectExecutorWake::class, 2);
    expect(app(ExecutorWake::class)->recoverPendingHooks())->toBe(0);
});

it('keeps an in-bound success authorized without touching the background queue', function (string $status): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => $status]);
    $bus = Bus::getFacadeRoot();
    Bus::partialMock()->shouldNotReceive('dispatch');
    $started = hrtime(true);
    try {
        $response = executorWakeVisit($instance);
    } finally {
        Bus::swap($bus);
    }
    $elapsedMs = (hrtime(true) - $started) / 1e6;

    $response->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
    expect($elapsedMs)->toBeLessThan(500);
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Succeeded);
    expect($operation->state[ExecutorWake::FollowUpState])->toBe('pending');
    Queue::assertNotPushed(ProjectExecutorWake::class);
    executorWakeSweep();
    executorWakeSweep();
    Queue::assertPushed(ProjectExecutorWake::class, 1);
    $contribution = $this->mock(InstanceLifecycleContribution::class);
    $contribution->shouldReceive('projectWake')->once();
    app()->tag([InstanceLifecycleContribution::class], InstanceLifecycleContribution::class);
    $job = Queue::pushed(ProjectExecutorWake::class)->sole();
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($operation->refresh()->state[ExecutorWake::FollowUpState])->toBe('delivered');
})->with(['done', 'partial']);

it('answers still waking and recovers a failed initial completion enqueue without another visit', function (bool $publish, bool $reply): void {
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
        executorWakeSweep();
    } finally {
        Bus::swap($bus);
    }

    $response->assertServiceUnavailable();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($operation)->status->toBe(OperationStatus::Running);
    expect($operation->state['wake_executor_completion'])->toBe('pending');
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, $publish ? 1 : 0);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    if ($publish) {
        Log::shouldHaveReceived('warning')->with('An executor wake completion handoff could not be recovered; the next pass tries again.',
            ['operation_id' => $operation->id, 'exception_type' => RuntimeException::class])->once();
    } else {
        $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    }
    if ($reply) {
        executorWakeReply($operation, ['status' => 'done']);
    }
    $this->travel(7)->seconds();
    config(['orbit.executor.wake_enabled' => false]);
    executorWakeSweep();
    app()->call([new ReconcileSwampOperations, 'handle']);

    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    expect($operation->refresh()->state['wake_executor_completion'])->toBe('queued');
    $job = Queue::pushed(CompleteExecutorWake::class)->sole();
    expect($job->delay->isPast())->toBe($publish);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    executorWakeSweep();
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
        executorWakeSweep();
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
    executorWakeSweep();
    executorWakeSweep();
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
        executorWakeVisit($instance)->assertServiceUnavailable();
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

    executorWakeSweep();
    executorWakeSweep();
    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');

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
    executorWakeVisit($instance)->assertServiceUnavailable();
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
        executorWakeSweep();
    } finally {
        Bus::swap($bus);
    }

    expect($operation->refresh()->state)->toMatchArray([ExecutorWake::CompletionState => 'pending']);
    expect($operation->state[ExecutorWake::PublishedAt])->toBeInt();
    $publishedAt = $operation->state[ExecutorWake::PublishedAt];
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    $this->travel(2)->seconds();
    executorWakeSweep();
    executorWakeSweep();
    executorWakeVisit($instance)->assertServiceUnavailable();
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect($operation->refresh()->state[ExecutorWake::PublishedAt])->toBe($publishedAt);
    $job = Queue::pushed(CompleteExecutorWake::class)->sole();
    expect($job->delay->getTimestampMs())->toBe($publishedAt + 6000);
    executorWakeReply($operation, ['status' => 'done']);
    app()->call([$job, 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('recovers a recorded result despite an older unpublished wake failing to send', function (): void {
    $instance = executorWakeInstance();
    $oldest = executorWakePendingCompletion($instance, 20);
    $completed = executorWakePendingCompletion($instance, 10);
    executorWakeReply($completed, ['status' => 'done']);
    $this->publisher->publishing = static function (ExecutorOrderBroadcast $event) use ($oldest): never {
        expect((int) $event->order->operation)->toBe($oldest->id);

        throw new RuntimeException('Injected publish failure.');
    };

    executorWakeSweep();

    expect($oldest->refresh()->state[ExecutorWake::CompletionState])->toBe('pending');
    $job = Queue::pushed(CompleteExecutorWake::class)->sole();
    expect($job->operationId)->toBe($completed->id);
    app()->call([$job, 'handle']);
    expect($completed->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('leaves a missing publication pending on sweep failure and recovers after publishing returns', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');

    executorWakeSweep();
    executorWakeSweep();

    expect($operation->refresh()->state)->not->toHaveKey(ExecutorWake::PublishedAt);
    expect($operation->state[ExecutorWake::CompletionState])->toBe('pending');
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    $this->publisher->publishing = null;
    executorWakeSweep();
    executorWakeSweep();
    executorWakeVisit($instance)->assertServiceUnavailable();
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
        executorWakeVisit($instance)->assertServiceUnavailable();
        expect((hrtime(true) - $started) / 1e6)->toBeLessThan(500);
        Queue::assertNotPushed(CompleteExecutorWake::class);
        executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);
    };

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);

    expect($raced)->toBeTrue();
    expect($operation->refresh()->state)->toHaveKey(ExecutorWake::PublishedAt);
    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('lets a late visit recover an unpublished order and leaves no duplicate work for the sweeps', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Injected publish failure.');
    executorWakeVisit($instance)->assertServiceUnavailable();
    $operation = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    $this->travel(7)->seconds();
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => executorWakeReply(Operation::query()->findOrFail((int) $event->order->operation), ['status' => 'done']);

    executorWakeVisit($instance)->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1');
    executorWakeSweep();
    executorWakeSweep();

    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertNotPushed(CompleteExecutorWake::class);
    Queue::assertPushed(ProjectExecutorWake::class, 1);
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

it('does not wait on a held publication lock and recovers once its owner leaves', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $this->freezeTime();
    app(ExecutorWakeLock::class)->run('publish', $operation->id, function () use ($instance): void {
        $started = hrtime(true);
        executorWakeVisit($instance)->assertServiceUnavailable();
        expect((hrtime(true) - $started) / 1e6)->toBeLessThan(500);
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
        Event::assertNotDispatched(ExecutorOrderBroadcast::class);
        Queue::assertNotPushed(CompleteExecutorWake::class);
        $this->travel(31)->seconds();
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
    });
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
    Event::assertDispatchedTimes(ExecutorOrderBroadcast::class, 1);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
});

it('does not start a second publication even if its owner stalls across recovery passes', function (): void {
    $instance = executorWakeInstance();
    $operation = executorWakePendingCompletion($instance, 7);
    $recordedAt = null;
    $this->publisher->publishing = function () use ($operation, &$recordedAt): void {
        $this->travel(31)->seconds();
        expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
        expect($operation->refresh()->state)->not->toHaveKey(ExecutorWake::PublishedAt);
        $recordedAt = now()->getTimestampMs();
    };

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);

    expect($operation->refresh()->state[ExecutorWake::PublishedAt])->toBe($recordedAt);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    expect(Queue::pushed(CompleteExecutorWake::class)->sole()->delay->getTimestampMs())->toBe($recordedAt + 6000);
    expect(array_map(fn (array $arguments): string => $arguments[0]->order->operation,
        Event::dispatched(ExecutorOrderBroadcast::class)->all()))->toBe([(string) $operation->id]);
    executorWakeReply($operation, ['status' => 'done']);
    app()->call([Queue::pushed(CompleteExecutorWake::class)->sole(), 'handle']);
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
});
