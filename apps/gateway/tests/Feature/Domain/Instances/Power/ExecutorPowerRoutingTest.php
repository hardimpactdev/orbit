<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Executor\ExecutorOrderBroadcast;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Instances\Power\ExecutorPowerIntent;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Executor\ExecutorJournalLookup;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Operations\BroadcastOperationNotice;
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

/**
 * The Node's answer to the read-only journal lookup: its clock, whether its executor records an order when it accepts it, then the
 * journal lines for the order. Each call takes the next answer; a null answer is an unreachable Node. Collects every remote command so
 * a test can show that no order was sent.
 *
 * @param  list<list<array<string, mixed>>|null>  $answers
 * @return ArrayObject<int, list<string>>
 */
function journalAnswers(array $answers, int $aheadMs = 60_000, bool $accepts = true): ArrayObject
{
    $commands = new ArrayObject;
    test()->mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use (&$answers, $commands, $aheadMs, $accepts): CommandResult {
        expect($connection->connectTimeout)->toBeLessThanOrEqual(2);
        $commands->append($command->arguments);
        $records = array_shift($answers);
        if ($records === null) {
            return new CommandResult(255, '', 'ssh: connect to host 10.44.0.7 port 22: No route to host', 3, false);
        }

        return new CommandResult(0, implode("\n", [(string) (now()->getTimestampMs() + $aheadMs), $accepts ? 'accepts' : 'legacy',
            ...array_map(static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR), $records)])."\n", '', 3, false);
    });

    return $commands;
}

/**
 * @param  array<string, mixed>|null  $outcome
 * @return array<string, mixed>
 */
function journalRecord(Operation $operation, string $action, string $record, ?array $outcome = null): array
{
    return ['record' => $record, 'nonce' => str_repeat('a', 32), 'operation' => (string) $operation->id, 'action' => $action,
        'target' => (string) $operation->target_id, ...($outcome === null ? ['expires_ms' => 1] : ['outcome' => $outcome, 'at_ms' => 1])];
}

/** Runs the journal read that the reconcile pass queued for the Operation, as its own job. */
function runQueuedJournalRead(Operation $operation): void
{
    $job = Queue::pushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->operationId === $operation->id && $job->delay === null)->last();
    expect($job)->not->toBeNull();
    $job->handle(app(ExecutorWake::class));
}

/** An Operation that ended under its fence when its Node could not be read, with a fresh Node clock for the next read. */
function interruptedByUnreachableNode(Operation $operation): void
{
    test()->travel(7)->seconds();
    journalAnswers([null]);
    app(ExecutorWake::class)->completeHandoff($operation);
    expect($operation->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown')->lock_key->not->toBeNull();
    test()->travel(10)->seconds();
}

/** @param ArrayObject<int, list<string>> $commands */
function onlyJournalReads(ArrayObject $commands): void
{
    foreach ($commands as $arguments) {
        expect($arguments)->toContain(ExecutorJournalLookup::Path)->not->toContain('relay');
    }
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

it('projects the residual-memory safety stop as stopped, with no frozen plan for the next wake', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use ($instance): void {
        powerLine($event, ['status' => 'accepted']);
        powerLine($event, ['status' => 'done', 'action' => 'sleep', 'target' => (string) $instance->id, 'operation' => $event->order->operation,
            'receipt' => ['sleep' => 'stopped', 'plan' => 'skipped', 'reason' => 'stopped', 'processes' => 17,
                'residual_anon_bytes' => 39731200, 'residual_file_bytes' => 11628544, 'swap_bytes' => 0, 'swap_subtree' => 1]]);
    };

    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    expect($sleep->refresh())->status->toBe(OperationStatus::Succeeded);
    expect($sleep->result)->toMatchArray(['sleep' => 'stopped', 'power_state' => 'stopped', 'swap_subtree' => 1]);
    expect($sleep->result['prepared_restore'])->toMatchArray(['status' => 'skipped', 'reason' => 'stopped',
        'processes' => 17, 'residual_anon_bytes' => 39731200, 'residual_file_bytes' => 11628544]);
    $runtime = $instance->runtime()->firstOrFail();
    expect($runtime->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    expect(ExecutorPowerIntent::frozenSleep($instance, $runtime))->toBeNull();
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('fails a wake the Node never accepted once its journal shows no record after expiry, with no relay and no Swamp run', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Running)->executor_accepted_us->toBeNull();

    $this->travel(6)->seconds();
    $commands = journalAnswers([[]]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.not_accepted')->lock_key->toBeNull();
    expect($commands)->toHaveCount(1);
    onlyJournalReads($commands);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps the fence while the Node clock has not passed the order expiry', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(6)->seconds();
    journalAnswers([[]], aheadMs: -3_000);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Running)->lock_key->not->toBeNull();
});

it('reads the journal after a publish that threw, and takes the result the Node recorded', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $this->publisher->publishing = fn () => throw new RuntimeException('The old WebSocket server closed after delivery.');
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Running)->lock_key->not->toBeNull();
    expect(fn () => app(InstanceSleep::class)->start($instance, 'cli', null))->toThrow(ResourceOperationException::class);

    $this->travel(6)->seconds();
    $commands = journalAnswers([[journalRecord($wake, 'wake', 'open'), journalRecord($wake, 'wake', 'done', ['status' => 'done'])]]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull();
    expect($wake->executor_result)->toMatchArray(['status' => 'done', 'delivery' => 'journal']);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
    onlyJournalReads($commands);
});

it('fails a wake whose publish threw once the journal shows the Node never acted', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $this->publisher->publishing = fn () => throw new RuntimeException('No websocket role is active.');
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(6)->seconds();
    journalAnswers([[]]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.publish_failed')->lock_key->toBeNull();
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('projects a late explicit wake and notifies its follower before the recovery deadline', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => powerLine($event, ['status' => 'accepted']);

    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Running)->executor_result->toBeNull();
    $recovery = Queue::pushed(CompleteExecutorWake::class)->sole();
    expect($recovery->delay->getTimestampMs())->toBe(now()->getTimestampMs() + 6000);

    $this->travel(1100)->milliseconds();
    executorWakeReply($wake, ['status' => 'done', 'repeat' => false]);
    executorWakeReply($wake, ['status' => 'done', 'repeat' => true]);
    $prompt = Queue::pushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->delay === null)->sole();
    expect($prompt->operationId)->toBe($wake->id)->and($prompt->queue)->toBe('operations-prompt');
    expect($wake->refresh()->executor_result)->toMatchArray(['status' => 'done', 'delivery' => 'websocket', 'repeat' => false]);
    expect(now()->isBefore($recovery->delay))->toBeTrue();

    $prompt->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull();
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
    $terminalNotice = fn (BroadcastOperationNotice $job): bool => $job->operationId === $wake->id
        && $job->channel === 'operation.'.$wake->id && $job->notice['status'] === 'succeeded'
        && $job->notice['sequence'] === $wake->sequence;
    expect(Queue::pushed(BroadcastOperationNotice::class, $terminalNotice))->toHaveCount(1);

    $this->travel(6)->seconds();
    $recovery->handle(app(ExecutorWake::class));
    expect(Queue::pushed(BroadcastOperationNotice::class, $terminalNotice))->toHaveCount(1);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('signs a headless wake without relaxing a web Instance readiness check', function (): void {
    $wake = app(InstanceWake::class)->admitDeferred(managedPowerFixture(), 'cli', null);
    foreach ([null, ['listen' => 8080]] as $web) {
        $wake->input = [...$wake->input, 'container' => ['web_config' => $web]];
        $wire = ExecutorPowerIntent::sign($wake)->wire;
        $payload = json_decode(base64_decode(explode('.', $wire, 2)[0], true), true, flags: JSON_THROW_ON_ERROR);
        expect($payload['power_check'] ?? null)->toBe($web === null ? 'no_web' : null);
    }
});

it('hands a wake that found its container stopped to the Swamp boot and signs only a fresh order for the bound run', function (): void {
    $instance = managedPowerFixture();
    $swamp = containerDefaultNode('swamp-worker', '10.44.0.8', listed: false);
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $nativeNonce = null;
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use (&$nativeNonce, $instance): void {
        $nativeNonce = $event->order->nonce;
        powerLine($event, ['status' => 'accepted']);
        powerLine($event, ['status' => 'failed', 'code' => 'instance.container_stopped', 'action' => 'wake',
            'target' => (string) $instance->id, 'operation' => $event->order->operation]);
    };
    $this->travelTo(Carbon::now()->startOfSecond());
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'conn', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    expect(Queue::pushed(StartAdmittedSwampRun::class)->sole()->operationId)->toBe($wake->id);
    expect($wake->refresh())->status->toBe(OperationStatus::Queued)->kind->toBe(InstanceWake::Kind);
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

it('promptly projects a late executor refusal or action failure without recovering it', function (array $line, string $code): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => powerLine($event, ['status' => 'accepted']);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(1100)->milliseconds();
    executorWakeReply($wake, $line);
    Queue::pushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->delay === null)->sole()
        ->handle(app(ExecutorWake::class));

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

it('signs only the lifecycle action admitted for the bound run', function (string $kind, string $phase, string $wrong): void {
    $instance = managedPowerFixture();
    $swamp = containerDefaultNode('swamp-worker', '10.44.0.8', listed: false);
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $intent = ['signed_power' => true, 'executor_lifecycle_phase' => $phase];
    $input = match ($kind) {
        'instance.deploy' => ['deployment' => $intent],
        default => $intent,
    };
    $operation = app(StartOperationAction::class)->execute($kind, 'instance', $instance->id, $instance->node_id, $input,
        'cli', null, start: false);
    $run = (string) Str::uuid();
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);
    $path = '/api/v1/direct-operations/'.$operation->id.'/executor/sign';
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.8'])->postJson($path, ['run_id' => $run, 'phase' => $wrong])->assertForbidden();
    $response = $this->postJson($path, ['run_id' => $run, 'phase' => $phase])->assertOk();
    $payload = json_decode(base64_decode(explode('.', $response->json('data.order'), 2)[0], true), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['action'])->toBe($phase === 'resume' ? 'wake' : 'discard');
    if ($phase === 'resume') {
        expect($payload['power_check'])->toBe('resume');
    }
})->with([
    'resume start' => [InstanceStart::Kind, 'resume', 'boot'],
    'resume deploy' => ['instance.deploy', 'resume', 'boot'],
    'boot deploy' => ['instance.deploy', 'boot', 'resume'],
    'boot start' => [InstanceStart::Kind, 'boot', 'resume'],
]);

it('signs no order for a destroy, which only removes both engines\' files', function (): void {
    $instance = managedPowerFixture();
    $swamp = containerDefaultNode('swamp-worker', '10.44.0.8', listed: false);
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $operation = app(StartOperationAction::class)->execute('instance.destroy', 'instance', $instance->id, $instance->node_id,
        ['container_destroy' => ['name' => 'instance-'.$instance->id]], 'cli', null, start: false);
    $run = (string) Str::uuid();
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.8'])
        ->postJson('/api/v1/direct-operations/'.$operation->id.'/executor/sign', ['run_id' => $run, 'phase' => 'removal'])
        ->assertUnprocessable();
    expect(ExecutorPowerIntent::action($operation->refresh(), 'boot'))->toBeNull();
});

it('does not replace a recorded refusal with a conflicting recovery success', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = (string) Str::uuid();
    $wake->update(['status' => OperationStatus::Running, 'state' => [...$wake->state, ExecutorWake::Recovery => true, 'swamp_run_id' => $run]]);
    executorWakeReply($wake, ['status' => 'refused', 'reason' => 'expired']);

    app(InstanceWake::class)->apply($wake, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['executor' => ['action' => 'wake', 'delivery' => 'swamp',
            'status' => 'done', 'target' => (string) $instance->id, 'operation' => (string) $wake->id]]]);

    expect($wake->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($wake->result['executor'])->toBe($wake->executor_result)
        ->and($wake->executor_result['status'])->toBe('refused');
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('keeps the signed sleep outcome instead of an outer frozen claim', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'running']);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $run = (string) Str::uuid();
    $sleep->update(['status' => OperationStatus::Running, 'state' => [...$sleep->state, ExecutorWake::Recovery => true, 'swamp_run_id' => $run]]);

    app(InstanceSleep::class)->apply($sleep, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['sleep' => 'frozen', 'executor' => ['status' => 'done',
            'action' => 'sleep', 'target' => (string) $instance->id, 'operation' => (string) $sleep->id, 'receipt' => ['sleep' => 'stopped']]]]);
    expect($sleep->refresh()->result['sleep'])->toBe('stopped');
});

it('rejects a successful signed resume wrapper with no successful executor receipt', function (?string $verdict, string $code): void {
    $instance = managedPowerFixture();
    $operation = app(StartOperationAction::class)->execute(InstanceStart::Kind, 'instance', $instance->id, $instance->node_id,
        ['signed_power' => true, 'executor_lifecycle_phase' => 'resume'], 'cli', null, start: false);
    $run = (string) Str::uuid();
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);
    $phase = $verdict === null ? [] : ['executor' => ['status' => $verdict, 'reason' => 'unknown_target',
        'operation' => (string) $operation->id, 'action' => 'wake', 'target' => (string) $instance->id]];
    app(InstanceStart::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $phase]);
    expect($operation->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe($code);
    expect($operation->result['executor']['status'] ?? null)->toBe($verdict);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
})->with(['refused' => ['refused', 'executor.order_refused'], 'missing' => [null, 'executor.result_unproven']]);

it('interrupts an accepted sleep under its fence while the Node is unreachable, then projects its lost reply from the Node journal', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => InstanceRuntimePowerState::Running]);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => powerLine($event, ['status' => 'accepted']);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    expect($sleep->refresh())->status->toBe(OperationStatus::Running)->executor_accepted_us->not->toBeNull();

    $this->travel(7)->seconds();
    $commands = journalAnswers([null, [journalRecord($sleep, 'sleep', 'done', ['status' => 'slept',
        'receipt' => ['sleep' => 'frozen', 'plan' => 'ready', 'processes' => 12, 'swap_subtree' => 3]])]]);
    app(ExecutorWake::class)->completeHandoff($sleep);
    expect($sleep->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown')
        ->lock_key->not->toBeNull()->executor_result->toBeNull();
    expect(fn () => app(InstanceWake::class)->start($instance, 'cli', null))->toThrow(ResourceOperationException::class);

    $this->travel(10)->seconds();
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
    runQueuedJournalRead($sleep);
    expect($sleep->refresh())->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull();
    expect($sleep->result)->toMatchArray(['sleep' => 'frozen', 'power_state' => 'stopped']);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    expect($commands)->toHaveCount(2);
    onlyJournalReads($commands);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('frees an interrupted wake once the reachable journal shows the Node never acted', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    $this->travel(6)->seconds();
    $commands = journalAnswers([null, []]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Interrupted)->lock_key->not->toBeNull();

    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(0);
    $this->travel(10)->seconds();
    app(ExecutorWake::class)->recoverPendingCompletions();
    runQueuedJournalRead($wake);
    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.not_accepted')->lock_key->toBeNull();
    expect($commands)->toHaveCount(2);
    onlyJournalReads($commands);
});

it('interrupts rather than releasing the target when recovery ends without a receipt', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = (string) Str::uuid();
    $wake->update(['status' => OperationStatus::Running, 'attempts' => 1, 'executor_accepted_us' => (int) now()->format('Uu'),
        'state' => [...$wake->state, ExecutorWake::PublishedAt => now()->subSeconds(120)->getTimestampMs(),
            ExecutorWake::Recovery => true, 'swamp_run_id' => $run]]);
    app(InstanceWake::class)->apply($wake, ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['error_code' => 'executor.relay_transport']]);

    expect($wake->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown')->lock_key->not->toBeNull();
    expect(fn () => app(InstanceWake::class)->admitDeferred($instance, 'cli', null))->toThrow(ResourceOperationException::class, 'owns this target');
});

it('keeps a recorded Python freeze on Python when the standalone wake flag is on', function (): void {
    $instance = managedPowerFixture();
    config(['orbit.executor.power_enabled' => false, 'orbit.executor.wake_enabled' => true]);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $sleep->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'result' => ['sleep' => 'frozen']]);

    expect(ExecutorWake::owns(app(InstanceWake::class)->admitDeferred($instance, 'cli', null)))->toBeFalse();
});

it('R3: wakes, starts and deploys a Python freeze through Python after both flags go on', function (): void {
    $instance = managedPowerFixture();
    config(['orbit.executor.power_enabled' => false, 'orbit.executor.wake_enabled' => false]);
    $sleep = app(InstanceSleep::class)->start($instance, 'runtime-hibernator', null);
    expect(ExecutorPowerIntent::managed($sleep))->toBeFalse();
    $sleep->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'result' => ['sleep' => 'frozen']]);

    config(['orbit.executor.power_enabled' => true, 'orbit.executor.wake_enabled' => true]);
    $runtime = $instance->runtime()->firstOrFail();
    expect(ExecutorPowerIntent::lifecycle($instance, $runtime))->toBe([]);
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'conn', null);
    expect([ExecutorWake::owns($wake), ExecutorPowerIntent::managed($wake), $wake->state[InstanceWake::ResumeState] ?? null])
        ->toBe([false, false, 'node']);
});

it('records a signed freeze that failed after freezing and wakes it with the same engine', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'running']);
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use ($instance): void {
        powerLine($event, ['status' => 'accepted']);
        powerLine($event, ['status' => 'failed', 'code' => 'instance.container_sleep_incomplete', 'action' => 'sleep',
            'target' => (string) $instance->id, 'operation' => $event->order->operation]);
    };
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    app(ExecutorWake::class)->continueHandoff($sleep->id);
    app(ExecutorWake::class)->completeHandoff($sleep->refresh());
    expect($sleep->refresh()->status)->toBe(OperationStatus::Failed);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    config(['orbit.executor.power_enabled' => false]);
    expect(ExecutorPowerIntent::managed(app(InstanceWake::class)->admitDeferred($instance, 'cli', null)))->toBeTrue();
});

it('fails before any delivery with a missing signing key and releases the target', function (): void {
    $instance = managedPowerFixture();
    config(['orbit.executor.signing_key' => null]);
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $this->publisher->publishing = static fn () => throw new LogicException('The fixture must not publish');

    app(ExecutorWake::class)->continueHandoff($wake->id);
    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.sign_failed')->lock_key->toBeNull();
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

/** A bound Swamp recovery of a published signed Operation. */
function inRecoveryRun(Operation $operation): string
{
    $run = (string) Str::uuid();
    $operation->update(['status' => OperationStatus::Running, 'attempts' => 1, 'state' => [...$operation->state,
        ExecutorWake::PublishedAt => now()->subSeconds(10)->getTimestampMs(), ExecutorWake::Recovery => true, 'swamp_run_id' => $run]]);

    return $run;
}

/** @param array<string, mixed> $phase
 * @return array<string, mixed>
 */
function runEnded(string $run, array $phase, string $status = 'failed'): array
{
    return ['run_id' => $run, 'status' => $status, 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $phase];
}

/** A start admitted with a signed lifecycle phase whose bound Swamp run asked the Gateway to sign it. */
function signedLifecycleStart(Instance $instance, string $phase): array
{
    $swamp = containerDefaultNode('swamp-worker', '10.44.0.8', listed: false);
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $operation = app(StartOperationAction::class)->execute(InstanceStart::Kind, 'instance', $instance->id, $instance->node_id,
        ['signed_power' => true, 'executor_lifecycle_phase' => $phase], 'cli', null,
        lockKey: OperationStore::lockKey('instance', $instance->id), start: false);
    $run = (string) Str::uuid();
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $run]]);
    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.8'])->postJson('/api/v1/direct-operations/'.$operation->id.'/executor/sign',
        ['run_id' => $run, 'phase' => $phase])->assertOk();

    return [$operation->refresh(), $run];
}

it('R1: keeps a recorded freeze when the later Swamp transport fails', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'running']);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $run = inRecoveryRun($sleep);
    executorWakeReply($sleep, ['status' => 'done', 'action' => 'sleep', 'receipt' => ['sleep' => 'frozen']]);
    app(InstanceSleep::class)->apply($sleep, runEnded($run, ['error_code' => 'executor.relay_transport']));
    expect($sleep->refresh())->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull();
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    config(['orbit.executor.power_enabled' => false]);
    expect(ExecutorPowerIntent::managed(app(InstanceWake::class)->admitDeferred($instance, 'cli', null)))->toBeTrue();
});

it('R1: reports the boot and its readiness failure after a stopped-container handoff', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = inRecoveryRun($wake);
    executorWakeReply($wake, ['status' => 'failed', 'code' => 'instance.container_stopped']);
    app(InstanceWake::class)->apply($wake, runEnded($run, ['wake' => 'started', 'error_code' => 'instance.t3_unready']));
    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('instance.t3_unready');
    expect($wake->result['wake'] ?? null)->toBe('started');
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('R2: keeps the fence of an issued lifecycle order whose reply was lost', function (): void {
    $instance = managedPowerFixture();
    [$operation, $run] = signedLifecycleStart($instance, 'resume');
    expect(fn () => app(CancelOperationAction::class)->execute($operation))->toThrow(ResourceOperationException::class);
    app(InstanceStart::class)->apply($operation, runEnded($run, ['error_code' => 'executor.relay_transport']));
    expect($operation->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown')
        ->lock_key->not->toBeNull();
    expect(fn () => app(InstanceSleep::class)->start($instance, 'cli', null))->toThrow(ResourceOperationException::class);
});

it('R2: never projects an issued boot discard as success without its receipt', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'stopped']);
    [$operation, $run] = signedLifecycleStart($instance, 'boot');
    app(InstanceStart::class)->apply($operation, runEnded($run, [], 'succeeded'));
    expect($operation->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown')
        ->lock_key->not->toBeNull();
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('R2: settles a cancel between the running claim and the signature at once', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $wake->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now()]);
    app(CancelOperationAction::class)->execute($wake);
    expect($wake->refresh())->status->toBe(OperationStatus::Cancelled)->lock_key->toBeNull();
    expect(ExecutorWake::completionDeadline($wake))->toBeNull();
    Queue::fake();
    expect(app(ExecutorWake::class)->request($wake))->toBeFalse();
    expect($wake->refresh()->state[ExecutorWake::PublishedAt] ?? null)->toBeNull();
});

it('R3: keeps the freeze with its engine past a cancelled later sleep', function (): void {
    $instance = managedPowerFixture();
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $sleep->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'result' => ['sleep' => 'frozen']]);
    $again = app(InstanceSleep::class)->start($instance, 'cli', null);
    app(CancelOperationAction::class)->execute($again);
    expect($again->refresh()->status)->toBe(OperationStatus::Cancelled);
    config(['orbit.executor.power_enabled' => false]);
    expect(ExecutorPowerIntent::managed(app(InstanceWake::class)->admitDeferred($instance, 'cli', null)))->toBeTrue();
});

it('R4: shows an interrupted wake as failed to a visit and to its reload under its fence', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = inRecoveryRun($wake);
    app(InstanceWake::class)->apply($wake, runEnded($run, ['error_code' => 'executor.relay_transport']));
    expect($wake->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown');
    foreach (['visit', 'reload'] as $visit) {
        expect(app(InstanceWake::class)->activate($instance, 'node'))->toContain('executor.result_unknown');
    }
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);
});

it('R1: keeps a durable freeze when Swamp returns no command output', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'running']);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $run = inRecoveryRun($sleep);
    executorWakeReply($sleep, ['status' => 'done', 'action' => 'sleep', 'receipt' => ['sleep' => 'frozen']]);
    app(InstanceSleep::class)->apply($sleep, runEnded($run, ['error_code' => 'operation.native_failed']));
    expect([$sleep->refresh()->executor_result['status'], $sleep->status->value, $instance->runtime()->firstOrFail()->power_state->value])->toBe(['done', 'succeeded', 'stopped']);
});

it('R1: keeps a recorded freeze when the recovery start is refused', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'running']);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $sleep->update(['state' => [...$sleep->state, ExecutorWake::PublishedAt => now()->subSeconds(7)->getTimestampMs(), ExecutorWake::Recovery => true]]);
    executorWakeReply($sleep, ['status' => 'done', 'action' => 'sleep', 'receipt' => ['sleep' => 'frozen']]);
    app(StartSwampRun::class)->refuse($sleep, new ResourceOperationException('swamp.start_unavailable', 'Start unavailable.', 503));
    expect([$sleep->refresh()->executor_result['status'], $sleep->status->value, $instance->runtime()->firstOrFail()->power_state->value])->toBe(['done', 'succeeded', 'stopped']);
});

it('R1/R2: settles a held wake and frees its lock when the missing receipt arrives late', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = inRecoveryRun($wake);
    app(InstanceWake::class)->apply($wake, runEnded($run, ['error_code' => 'executor.relay_transport']));
    expect($wake->refresh()->status)->toBe(OperationStatus::Interrupted);
    executorWakeReply($wake, ['status' => 'done']);
    app(ExecutorWake::class)->completeHandoff($wake->refresh());
    executorWakeSweep();
    expect([$wake->refresh()->executor_result['status'], $wake->status->value, $wake->lock_key, $instance->runtime()->firstOrFail()->power_state->value])->toBe(['done', 'succeeded', null, 'running']);
});

it('R3: ends Rust freeze ownership with its discard receipt even when the ordinary boot fails', function (): void {
    $instance = managedPowerFixture();
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    $run = inRecoveryRun($sleep);
    executorWakeReply($sleep, ['status' => 'done', 'action' => 'sleep', 'receipt' => ['sleep' => 'frozen']]);
    app(InstanceSleep::class)->apply($sleep, runEnded($run, [], 'succeeded'));
    config(['orbit.executor.power_enabled' => false]);
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    $run = inRecoveryRun($wake);
    executorWakeReply($wake, ['status' => 'failed', 'code' => 'instance.container_stopped']);
    app(InstanceWake::class)->apply($wake, runEnded($run, ['wake' => 'started', 'error_code' => 'instance.container_start_failed']));
    expect($wake->refresh()->error_code)->toBe('instance.container_start_failed');
    expect(ExecutorPowerIntent::managed(app(InstanceWake::class)->admitDeferred($instance, 'cli', null)))->toBeFalse();
});

it('R1: takes a failed lifecycle receipt code when the start wrapper failed too', function (): void {
    $instance = managedPowerFixture();
    [$start, $run] = signedLifecycleStart($instance, 'resume');
    $receipt = ['action' => 'wake', 'operation' => (string) $start->id, 'target' => (string) $instance->id, 'status' => 'failed', 'code' => 'instance.container_frozen_unmanaged'];
    app(InstanceStart::class)->apply($start, runEnded($run, ['executor' => $receipt, 'error_code' => 'operation.native_failed']));
    expect([$start->refresh()->executor_result['code'], $start->error_code])->toBe(['instance.container_frozen_unmanaged', 'instance.container_frozen_unmanaged']);
});

it('R4: shows a held nested clone start as a failed wake on the visit and its reload', function (): void {
    $default = managedPowerFixture();
    $node = $default->node;
    $node->update(['settings' => [...($node->settings ?? []), 'nested_fleet' => true]]);
    $default->project->forceFill(['nested_fleet' => true])->save();
    $default->runtime()->delete();
    app(InstanceContainer::class)->reserve($default, $node->refresh())->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $instance = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $default->project_id, 'name' => 'copy', 'checkout_path' => '/workspace/copy', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    app(InstanceContainer::class)->reserve($instance, $node, $default)->update(['power_state' => 'stopped']);
    expect(NestedFleetIntent::cloneKeepsFleet($instance, $instance->runtime()->first()))->toBeTrue();
    [$start, $run] = signedLifecycleStart($instance, 'boot');
    app(InstanceStart::class)->apply($start, runEnded($run, ['error_code' => 'executor.relay_transport']));
    expect($start->refresh()->error_code)->toBe('executor.result_unknown');
    $visit = app(InstanceWake::class)->activate($instance, 'node');
    $reload = app(InstanceWake::class)->activate($instance, 'node');
    expect([$visit, $reload])->toBe(['The wake failed (executor.result_unknown). Reload the page to try again.', 'The wake failed (executor.result_unknown). Reload the page to try again.'])
        ->and(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(0);
});

it('R4: shows a wake that failed before its visit returned on that response', function (): void {
    $instance = managedPowerFixture();
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event): void {
        $operation = Operation::query()->findOrFail((int) $event->order->operation);
        executorWakeReply($operation, ['status' => 'failed', 'code' => 'instance.web_unready']);
        app(ExecutorWake::class)->completeHandoff($operation);
    };
    $visit = app(InstanceWake::class)->activate($instance, 'node');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->error_code)->toBe('instance.web_unready');
    expect($visit)->toBeString()->toContain('instance.web_unready');
});

it('R1: keeps a durable freeze when its bound recovery times out', function (): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => 'running']);
    $sleep = app(InstanceSleep::class)->start($instance, 'cli', null);
    inRecoveryRun($sleep);
    $sleep->update(['started_at' => now()->subDay()]);
    executorWakeReply($sleep, ['status' => 'done', 'action' => 'sleep', 'receipt' => ['sleep' => 'frozen']]);
    app(ExpireOperationAction::class)->execute($sleep->refresh());
    expect([$sleep->refresh()->executor_result['status'], $sleep->status->value, $sleep->error_code, $instance->runtime()->firstOrFail()->power_state->value])->toBe(['done', 'succeeded', null, 'stopped']);
});

it('R2: forwards a cancel once the stopped-container receipt ended the fence', function (): void {
    $instance = managedPowerFixture();
    $wake = app(InstanceWake::class)->admitDeferred($instance, 'cli', null);
    inRecoveryRun($wake);
    executorWakeReply($wake, ['status' => 'failed', 'code' => 'instance.container_stopped']);
    expect(ExecutorFence::holds($wake->refresh()))->toBeFalse();
    try {
        app(CancelOperationAction::class)->execute($wake);
        $code = null;
    } catch (ResourceOperationException $error) {
        $code = $error->errorCode;
    }
    expect($code)->toBeNull();
});

it('R1: takes a failed resume receipt code when the deploy wrapper failed too', function (): void {
    $instance = managedPowerFixture();
    Operation::query()->create(['kind' => InstanceSleep::Kind, 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Succeeded, 'input' => ['signed_power' => true], 'result' => ['sleep' => 'frozen']]);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance);
    expect($intent['executor_lifecycle_phase'])->toBe('resume');
    $run = (string) Str::uuid();
    $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id, 'status' => OperationStatus::Running, 'input' => ['deployment' => $intent], 'state' => ['swamp_run_id' => $run, 'executor_order_issued' => true]]);
    $file = ['executor' => ['status' => 'failed', 'code' => 'instance.container_frozen_unmanaged', 'action' => 'wake', 'target' => (string) $instance->id, 'operation' => (string) $deploy->id]];
    app(InstanceDeployProjection::class)->apply($deploy, runEnded($run, ['steps' => ['Read the container result' => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]]]));
    expect([$deploy->refresh()->executor_result['code'], $deploy->error_code])->toBe(['instance.container_frozen_unmanaged', 'instance.container_frozen_unmanaged']);
});

it('completes a receipt that the journal finds after an interruption exactly as an on-time receipt', function (string $action, array $outcome, OperationStatus $status, ?string $code, InstanceRuntimePowerState $power, bool $boot): void {
    $instance = managedPowerFixture();
    $instance->runtime()->update(['power_state' => $action === 'sleep' ? InstanceRuntimePowerState::Running : InstanceRuntimePowerState::Stopped]);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => powerLine($event, ['status' => 'accepted']);
    $operation = $action === 'sleep' ? app(InstanceSleep::class)->start($instance, 'cli', null) : app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    interruptedByUnreachableNode($operation);

    journalAnswers([[journalRecord($operation, $action, 'open'), journalRecord($operation, $action, 'done', $outcome)]]);
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(1);
    runQueuedJournalRead($operation);

    expect($operation->refresh())->status->toBe($status)->error_code->toBe($code)->lock_key->toBe($boot ? $operation->lock_key : null);
    expect($operation->executor_result)->toMatchArray(['delivery' => 'journal']);
    expect($instance->runtime()->firstOrFail()->power_state)->toBe($power);
    $boot ? Queue::assertPushed(StartAdmittedSwampRun::class) : Queue::assertNotPushed(StartAdmittedSwampRun::class);
    expect($operation->state[ExecutorWake::Recovery] ?? false)->toBe($boot);
})->with([
    'a stopped container goes to the ordinary boot' => ['wake', ['status' => 'failed', 'code' => 'instance.container_stopped'],
        OperationStatus::Queued, 'executor.result_unknown', InstanceRuntimePowerState::Stopped, true],
    'an incomplete freeze unlocks and reports the container stopped' => ['sleep', ['status' => 'failed', 'code' => InstanceSleep::IncompleteFreeze],
        OperationStatus::Failed, InstanceSleep::IncompleteFreeze, InstanceRuntimePowerState::Stopped, false],
    'a wake whose web check failed fails' => ['wake', ['status' => 'failed', 'code' => 'instance.web_unready'],
        OperationStatus::Failed, 'instance.web_unready', InstanceRuntimePowerState::Stopped, false],
    'a wake that finished succeeds' => ['wake', ['status' => 'done'], OperationStatus::Succeeded, null, InstanceRuntimePowerState::Running, false],
]);

it('completes a late WebSocket receipt after an interruption through the same completion', function (): void {
    $instance = managedPowerFixture();
    $published = null;
    $this->publisher->publishing = function (ExecutorOrderBroadcast $event) use (&$published): void {
        $published = $event;
        powerLine($event, ['status' => 'accepted']);
    };
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));
    interruptedByUnreachableNode($wake);

    powerLine($published, ['status' => 'failed', 'code' => 'instance.web_unready', 'action' => 'wake', 'target' => (string) $instance->id,
        'operation' => (string) $wake->id]);
    expect($wake->refresh())->status->toBe(OperationStatus::Interrupted);
    runQueuedJournalRead($wake);

    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('instance.web_unready')->lock_key->toBeNull();
});

it('reads an open order again within a second instead of waiting out the handoff lease', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(6)->seconds();
    $commands = journalAnswers([[journalRecord($wake, 'wake', 'open')], [journalRecord($wake, 'wake', 'done', ['status' => 'done'])]]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Running);
    $reread = Queue::pushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->delay === 1)->sole();
    expect($wake->state[ExecutorWake::CompletionState.'_until'])->toBe(now()->getTimestamp() + 1 + ExecutorWake::HandoffLeaseSeconds);

    $this->travel(1)->seconds();
    $reread->handle(app(ExecutorWake::class));
    expect($wake->refresh())->status->toBe(OperationStatus::Succeeded)->lock_key->toBeNull();
    expect($commands)->toHaveCount(2);
});

it('keeps an order fenced that was accepted and still waits on its Node past its expiry', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(6)->seconds();
    journalAnswers([[journalRecord($wake, 'wake', 'seen')]]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Running)->lock_key->not->toBeNull();
});

it('fails an order that expired on its Node while it waited for the target, without acting', function (): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel(6)->seconds();
    journalAnswers([[journalRecord($wake, 'wake', 'seen'), journalRecord($wake, 'wake', 'done', ['status' => 'failed', 'code' => 'executor.order_expired'])]]);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Failed)->error_code->toBe('executor.order_expired')->lock_key->toBeNull();
    expect($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    Queue::assertNotPushed(StartAdmittedSwampRun::class);
});

it('keeps the fence when the journal cannot prove absence', function (bool $accepts, int $ageSeconds): void {
    $instance = managedPowerFixture();
    $this->travelTo(now()->startOfSecond());
    $wake = app(InstanceWake::class)->start($instance, 'cli', null);
    Queue::pushed(DispatchExecutorWake::class)->sole()->handle(app(ExecutorWake::class));

    $this->travel($ageSeconds)->seconds();
    journalAnswers([[]], accepts: $accepts);
    (new CompleteExecutorWake($wake->id))->handle(app(ExecutorWake::class));

    expect($wake->refresh())->status->toBe(OperationStatus::Interrupted)->error_code->toBe('executor.result_unknown')->lock_key->not->toBeNull();
})->with([
    'an executor that does not record accepted orders' => [false, 6],
    'an order older than the journal keeps its done records' => [true, 86_400],
]);

it('queues each journal read on its own, so an unreachable Node holds up no other recovery', function (): void {
    $instance = managedPowerFixture();
    $other = Instance::query()->create(['node_id' => $instance->node_id, 'project_id' => $instance->project_id, 'name' => 'other',
        'checkout_path' => '/workspace/other', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false]);
    app(InstanceContainer::class)->reserve($other, $instance->node)->update(['power_state' => 'stopped']);
    $this->publisher->publishing = fn (ExecutorOrderBroadcast $event) => powerLine($event, ['status' => 'accepted']);
    $first = app(InstanceWake::class)->start($instance, 'cli', null);
    $second = app(InstanceWake::class)->start($other, 'cli', null);
    foreach (Queue::pushed(DispatchExecutorWake::class) as $job) {
        $job->handle(app(ExecutorWake::class));
    }
    interruptedByUnreachableNode($first);
    journalAnswers([null]);
    app(ExecutorWake::class)->completeHandoff($second);
    expect($second->refresh())->status->toBe(OperationStatus::Interrupted);
    $this->travel(10)->seconds();

    $commands = journalAnswers([]);
    expect(app(ExecutorWake::class)->recoverPendingCompletions())->toBe(2);
    expect($commands)->toHaveCount(0);
    expect(Queue::pushed(CompleteExecutorWake::class, fn (CompleteExecutorWake $job): bool => $job->delay === null)->pluck('operationId')->unique()->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});
