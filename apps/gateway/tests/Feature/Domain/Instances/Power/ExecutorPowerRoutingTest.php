<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\Power\ExecutorPowerIntent;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Jobs\Operations\DispatchExecutorWake;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/ExecutorWakeTestHelpers.php';

uses(LazilyRefreshDatabase::class);

function managedPowerFixture(): Instance
{
    $instance = executorWakeInstance();
    config(['orbit.executor.wake_enabled' => false, 'orbit.executor.power_enabled' => true,
        'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->instance(ExecutorResults::class, test()->results);

    return $instance;
}

/** @param array<string, mixed> $line */
function powerLine(ExecutorOrderBroadcast $event, array $line): void
{
    $reply = ExecutorReply::fromEvent(['operation' => $event->order->operation, 'line' => json_encode($line, JSON_THROW_ON_ERROR)]);
    test()->results->receive($event->nodeId, $reply ?? throw new LogicException('Invalid fixture receipt'));
}

it('projects a signed sleep and keeps its engine for the wake after disabling new admissions', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use ($instance): void {
        powerLine($event, ['status' => 'accepted']);
        powerLine($event, ['status' => 'done', 'action' => 'sleep', 'target' => (string) $instance->id,
            'operation' => $event->order->operation, 'receipt' => ['sleep' => 'frozen', 'plan' => 'ready', 'processes' => 12, 'swap_subtree' => 3]]);
    };

    $sleep = app(InstanceSleep::class)->start($instance, 'runtime-hibernator', null);
    $join = app(InstanceSleep::class)->start($instance, 'cli', null);
    expect($join->id)->toBe($sleep->id);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    expect($sleep->refresh())->kind->toBe(InstanceSleep::Kind)->status->toBe(OperationStatus::Succeeded);
    expect($sleep->result)->toMatchArray(['sleep' => 'frozen', 'swap_subtree' => 3, 'power_state' => 'stopped']);
    expect($sleep->result['executor']['delivery'])->toBe('websocket');
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
    config(['orbit.executor.power_enabled' => false]);
    expect(app(InstanceWake::class)->admitDeferred($instance, 'conn', null)->input[ExecutorPowerIntent::Managed])->toBeTrue();
});

it('relays the original signature after the no-accepted cutoff without a second Operation', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $publishedWireHash = null;
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use (&$publishedWireHash): void {
        $publishedWireHash = hash('sha256', $event->order->wire);
        $this->travel(151)->milliseconds();
    };
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $this->mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(
        function (SshConnection $connection, RemoteCommand $command) use ($wake, &$publishedWireHash): CommandResult {
            expect($connection->shareConnection)->toBeTrue();
            expect($command->arguments)->toBe(['sudo', '-n', '-u', 'orbit-executor-relay', '/usr/local/libexec/orbit-executor', 'relay']);
            expect($command->input)->toBeNull();
            $input = stream_get_contents($command->protectedInput->stream());
            expect($publishedWireHash === hash('sha256', trim($input)))->toBeTrue();
            $receipt = ['status' => 'done', 'action' => 'wake', 'target' => (string) $wake->target_id, 'operation' => (string) $wake->id];

            return new CommandResult(0, '{"status":"accepted"}'."\n".json_encode($receipt, JSON_THROW_ON_ERROR)."\n", '', 23, false);
        });

    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    expect($wake->refresh()->result['executor']['delivery'])->toBe('ssh');
    expect($wake)->status->toBe(OperationStatus::Succeeded);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('starts durable recovery at six seconds and signs only a fresh order for the bound Swamp run', function (): void {
    $instance = managedPowerFixture();
    $swamp = containerDefaultNode('swamp-worker', '10.44.0.8', listed: false);
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $nativeNonce = null;
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use (&$nativeNonce): void {
        $nativeNonce = $event->order->nonce;
        powerLine($event, ['status' => 'accepted']);
    };
    $this->travelTo(Carbon::now()->startOfSecond());
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'conn', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(5999)->milliseconds();
    (new CompleteExecutorWake($wake->id))->withFakeQueueInteractions()->handle(app(ExecutorWake::class));
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
    $this->travel(1)->millisecond();
    $job = new CompleteExecutorWake($wake->id);
    $job->handle(app(ExecutorWake::class));
    $job->handle(app(ExecutorWake::class));
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    expect(Queue::pushed(StartAdmittedSwampRun::class)->sole()->operationId)->toBe($wake->id);
    expect($wake->refresh())->status->toBe(OperationStatus::Queued)->kind->toBe(InstanceWake::Kind)->executor_result->toBeNull();
    $run = (string) Str::uuid();
    $wake->update(['status' => OperationStatus::Running, 'state' => [...$wake->state, 'swamp_run_id' => $run]]);

    $request = ['run_id' => $run, 'phase' => 'power'];
    $path = '/api/v1/direct-operations/'.$wake->id.'/executor/sign';
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->postJson($path, $request)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.8'])->postJson($path, [...$request, 'target' => '1'])->assertUnprocessable();
    $this->postJson($path, [...$request, 'run_id' => (string) Str::uuid()])->assertConflict();
    $this->postJson($path, [...$request, 'phase' => 'boot'])->assertForbidden();
    config(['orbit.executor.power_enabled' => false]);
    $response = $this->postJson($path, $request)->assertOk();
    $order = $response->json('data.order');
    $payload = json_decode(base64_decode(explode('.', $order, 2)[0], true), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['nonce'] !== $nativeNonce)->toBeTrue();
    expect($payload)->toMatchArray(['operation' => (string) $wake->id, 'action' => 'wake',
        'target' => (string) $instance->id, 'node' => (string) $instance->node_id, 'expires_ms' => now()->getTimestampMs() + 5000]);
    $wake->update(['cancel_requested_at' => now()]);
    $this->postJson($path, $request)->assertConflict();
});

it('does not recover a terminal executor refusal or action failure', function (array $line, string $code): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use ($wake, $line): void {
        powerLine($event, ['status' => 'accepted']);
        powerLine($event, ['operation' => $event->order->operation, 'action' => 'wake', 'target' => (string) $wake->target_id, ...$line]);
    };

    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe($code)->lock_key->toBeNull();
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
})->with([
    'refusal' => [['status' => 'refused', 'reason' => 'bad_signature'], 'executor.order_refused'],
    'failure' => [['status' => 'failed', 'code' => 'instance.web_unready'], 'instance.web_unready'],
]);

it('does not sign after a queued cancellation or release the hold of a started native action', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $cancel = app(CancelOperationAction::class);
    $cancel->execute($wake);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Cancelled)->lock_key->toBeNull();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => powerLine($event, ['status' => 'accepted']);
    app(ExecutorWake::class)->continueHandoff($wake->id);

    expect(fn () => $cancel->execute($wake))->toThrow(ResourceOperationException::class, 'has started');
    expect($wake->refresh())->status->toBe(OperationStatus::Running)->lock_key->not->toBeNull();
});

it('requires a matching frozen or stopped receipt when projecting recovered sleep', function (bool $matched): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $run = (string) Str::uuid();
    $sleep->update(['status' => OperationStatus::Running,
        'state' => [...$sleep->state, ExecutorWake::Recovery => true, 'swamp_run_id' => $run]]);

    app(InstanceSleep::class)->apply($sleep, ['run_id' => $run, 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['executor' => ['status' => 'done', 'action' => 'sleep', 'delivery' => 'swamp',
            'target' => (string) $instance->id, 'operation' => (string) ($sleep->id + ($matched ? 0 : 1)),
            'receipt' => ['sleep' => 'frozen', 'plan' => 'ready']]]]);

    expect($sleep->refresh())->status->toBe($matched ? OperationStatus::Succeeded : OperationStatus::Failed)
        ->error_code->toBe($matched ? null : 'executor.result_unproven');
    expect($instance->runtime()->firstOrFail()->power_state)
        ->toBe($matched ? InstanceRuntimePowerState::Stopped : InstanceRuntimePowerState::Running);
})->with(['matching' => [true], 'other Operation' => [false]]);

it('permits a stopped wake only when its recovery actually boots the ordinary container', function (string $path): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = (string) Str::uuid();
    $wake->update(['status' => OperationStatus::Running,
        'state' => [...$wake->state, ExecutorWake::Recovery => true, 'swamp_run_id' => $run]]);

    app(InstanceWake::class)->apply($wake, ['run_id' => $run, 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['wake' => $path,
            'executor' => ['status' => 'failed', 'code' => 'instance.container_stopped', 'action' => 'wake',
                'delivery' => 'swamp', 'target' => (string) $instance->id, 'operation' => (string) $wake->id]]]);

    expect($wake->refresh())->status->toBe($path === 'started' ? OperationStatus::Succeeded : OperationStatus::Failed);
    expect($instance->runtime()->firstOrFail()->power_state)
        ->toBe($path === 'started' ? InstanceRuntimePowerState::Running : InstanceRuntimePowerState::Stopped);
})->with(['started', 'already_running']);
