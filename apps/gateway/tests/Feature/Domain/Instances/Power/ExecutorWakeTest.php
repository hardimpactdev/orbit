<?php

declare(strict_types=1);

use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Ssh\SshExecutor;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Testing\TestResponse;
use Psr\Log\NullLogger;

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
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps 401 pending on a publish error and schedules its durable completion', function (): void {
    $instance = executorWakeInstance();
    $this->publisher->publishing = static fn () => throw new RuntimeException('Publish unavailable.');

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole())->status->toBe(OperationStatus::Running);
    Queue::assertPushed(CompleteExecutorWake::class, 1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps the existing wake when the setting is off and requires no signing key', function (): void {
    $instance = executorWakeInstance();
    config(['orbit.executor.wake_enabled' => false, 'orbit.executor.signing_key' => null]);

    executorWakeVisit($instance)->assertUnauthorized()->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->state)->not->toHaveKey(ExecutorWake::State);
    Event::assertNotDispatched(ExecutorOrderBroadcast::class);
    Queue::assertNotPushed(CompleteExecutorWake::class);
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
