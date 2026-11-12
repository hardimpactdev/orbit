<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Proof\ProofChildStarts;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ProofCommandProjectionTest.php';

/** The existing provision fixture, before native submission rather than after it. */
function proofPendingStarts(int $count = 3): array
{
    [$proof, $records] = proofRuns(array_slice(['firewall:allow', 'firewall:list', 'firewall:deny'], 0, $count));
    foreach ($records as [$child]) {
        $child->update(['status' => OperationStatus::Queued, 'state' => ['swamp_start' => [
            'key' => 'operation:'.$child->id, 'workflow' => 'operation-proof-run',
        ]], 'input' => [...$child->input, 'run_budget_seconds' => 2700]]);
    }
    DB::transaction(function () use ($records): void {
        foreach ($records as [$child]) {
            app(SwampDispatch::class)->start($child);
        }
    });

    return [$proof, $records];
}

/** A native answer after a controlled delay, without a real connection or clock wait. */
function proofStartAnswer(int $seconds = 0, bool $refuse = false, ?Closure $waiting = null): void
{
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $seconds, $refuse, $waiting): array {
        if ($frame['payload']['inputs']['kind'] === 'proof.command') {
            test()->travel($seconds)->seconds();
            $waiting?->__invoke();
        }

        return $refuse ? [['type' => 'error', 'id' => $frame['id'], 'error' => ['code' => 'refused']]]
            : [['type' => 'event', 'id' => $frame['id'], 'event' => ['kind' => 'started',
                'workflowName' => $frame['payload']['workflowIdOrName'], 'runId' => PROOF_RUN_ID]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    // The fixture has already captured its provision envelope; only the native start is under test.
    $projection = app(ProofCommandProjection::class);
    app()->instance(ProofCommandProjection::class, new class($projection) implements DirectOperationProjection, DirectOperationStartRefusal
    {
        public function __construct(private readonly ProofCommandProjection $projection) {}

        public function apply(Operation $operation, array $data): Operation
        {
            return $this->projection->apply($operation, $data);
        }

        public function refused(Operation $operation): void
        {
            $this->projection->refused($operation);
        }

        public function input(Operation $operation): array
        {
            return ['intent' => [], 'execution_secrets' => []];
        }
    });
}

it('spaces the successor from a slow answer and lets unrelated work run before it', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    $other = Operation::query()->create(['kind' => 'process.restart', 'target_type' => 'process', 'target_id' => 42,
        'node_id' => $first->node_id, 'input' => [], 'status' => OperationStatus::Queued]);
    proofStartAnswer(82, waiting: function () use ($other): void {
        app(SwampDispatch::class)->start($other);
    });
    app()->instance(ProcessControlProjection::class, new class implements DirectOperationProjection
    {
        public function input(Operation $operation): array
        {
            return ['intent' => [], 'execution_secrets' => []];
        }

        public function apply(Operation $operation, array $data): Operation
        {
            throw new LogicException('Only native submission is under test.');
        }
    });
    $began = now()->toImmutable();
    app()->call([new StartAdmittedSwampRun($first->id), 'handle']);
    app()->call([new StartAdmittedSwampRun($other->id), 'handle']);
    app()->call([new StartAdmittedSwampRun($first->id), 'handle']);
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('callback after confirmation'));

    expect($other->refresh()->status)->toBe(OperationStatus::Running)
        ->and($other->state['swamp_run_id'])->toBe(PROOF_RUN_ID);
    expect(now()->equalTo($began->addSeconds(82)))->toBeTrue();
    Queue::assertPushed(StartAdmittedSwampRun::class, 3);
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $third->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id
        && $job->queue === 'operations' && $job->connection === 'gateway' && $job->delay->equalTo($began->addSeconds(102)));
    $this->travel(19)->seconds();
    app()->call([new StartAdmittedSwampRun($second->id), 'handle']);
    expect($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($second->state['proof_child_start']['released_at'])->toBeNull();
});

it('continues after a definite job failure once and releases the failed provision through its existing projection', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $records] = proofPendingStarts();
    [[$first, $group, $vm], [$second], [$third]] = $records;
    $job = new StartAdmittedSwampRun($first->id);

    $job->failed(new RuntimeException('worker stopped before submission'));
    $job->failed(new RuntimeException('repeated failure callback'));
    app(ProofChildStarts::class)->advance($first->refresh());
    app()->call([$job, 'handle']);

    expect($first->refresh()->status)->toBe(OperationStatus::Failed)->and($first->error_code)->toBe('swamp.start_job_failed');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
    Queue::assertPushed(ReleaseTaskVmSlot::class, fn ($job): bool => $job->slotId === $vm->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay->equalTo(now()->addSeconds(20)));
    $this->travel(20)->seconds();
    proofStartAnswer();
    app()->call([new StartAdmittedSwampRun($second->id), 'handle']);
    expect($second->refresh()->status)->toBe(OperationStatus::Running);
    Queue::assertPushed(StartAdmittedSwampRun::class, 3);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $third->id && $job->delay->equalTo(now()->addSeconds(20)));
    $this->travel(20)->seconds();
    app()->call([new StartAdmittedSwampRun($third->id), 'handle']);
    expect($third->refresh()->status)->toBe(OperationStatus::Running);
    expect($first->refresh()->state)->not->toHaveKey('swamp_run_id');
});

it('continues after an explicit native refusal even when the failure handler also advances', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [[$first], [$second]] = $records;
    proofStartAnswer(refuse: true);
    $job = new StartAdmittedSwampRun($first->id);
    try {
        app()->call([$job, 'handle']);
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.start_refused');
        $job->failed($error);
    }

    expect($first->refresh()->error_code)->toBe('swamp.start_refused');
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay?->equalTo(now()->addSeconds(20)));
});

it('never resubmits an uncertain start or treats its missing answer as permission to advance', function (): void {
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [[$first], [$second]] = $records;
    app(ProofChildStarts::class)->release($first->refresh());
    $first->update(['status' => OperationStatus::Running, 'started_at' => now()]);
    $job = new StartAdmittedSwampRun($first->id);
    $job->failed(new RuntimeException('lost confirmation'));
    $job->failed(new RuntimeException('repeated callback'));
    app()->call([$job, 'handle']);

    expect($first->refresh()->status)->toBe(OperationStatus::Running)->and($first->error_code)->toBe('swamp.start_uncertain');
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    expect(OperationRunBudgets::deadline($second->refresh()))->toBeNull();
    $this->travel(601)->seconds();
    app(ExpireOperationAction::class)->execute($first);
    expect($first->refresh()->error_code)->toBe('operation.timed_out');
});

it('cancels deferred children through normal cancellation when the parent ends before their turn', function (string $end): void {
    $this->freezeTime();
    Queue::fake();
    [$proof, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('first failed'));
    if ($end === 'cancelled') {
        app(CancelOperationAction::class)->execute($proof);
    } else {
        $proof->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);
    }
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect');
    app()->instance(WebSocketClient::class, $socket);
    $this->travel(20)->seconds();
    expect(fn () => app()->call([new StartAdmittedSwampRun($second->id), 'handle']))->not->toThrow(ResourceOperationException::class);

    foreach ([$second, $third] as $child) {
        expect($child->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($child->cancel_requested_at)->not->toBeNull()->and($child->lock_key)->toBeNull()->and($child->error_code)->toBeNull();
        Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $child->id);
    }
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $third->id);
})->with(['cancelled', 'failed']);

it('holds the admission clock until release and preserves the captured execution budget', function (): void {
    $this->freezeTime();
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [[$first], [$second]] = $records;
    $this->travel(900)->seconds();
    expect(OperationRunBudgets::deadline($second->refresh()))->toBeNull();
    expect(app(ExpireOperationAction::class)->execute($second)->status)->toBe(OperationStatus::Queued);
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('first failed'));
    $this->travel(20)->seconds();
    app(ProofChildStarts::class)->release($second->refresh());

    expect(OperationRunBudgets::seconds($second->refresh()))->toBe(600);
    expect(OperationRunBudgets::deadline($second)->equalTo(now()->addSeconds(600)))->toBeTrue();
    expect($second->state['proof_child_start']['released_at'])->toBe(now()->toISOString());
    $this->travel(599)->seconds();
    expect(app(ExpireOperationAction::class)->execute($second)->status)->toBe(OperationStatus::Queued);
    $bound = $second->replicate()->forceFill(['state' => [...$second->state, 'swamp_run_id' => PROOF_RUN_ID], 'started_at' => now()]);
    expect(OperationRunBudgets::seconds($bound))->toBe(2700);
    $this->travel(1)->seconds();
    expect(app(ExpireOperationAction::class)->execute($second)->error_code)->toBe('operation.timed_out');
    expect($second->input['run_budget_seconds'])->toBe(2700);
});

it('leaves a lost enqueue for the existing watchdog without queuing a successor twice', function (): void {
    $this->freezeTime();
    Queue::fake();
    [$proof, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->withArgs(fn ($job): bool => $job instanceof StartAdmittedSwampRun && $job->operationId === $second->id)
        ->once()->andThrow(new RuntimeException('queue unavailable'));
    Bus::shouldReceive('dispatch')->withArgs(fn ($job): bool => ! $job instanceof StartAdmittedSwampRun || $job->operationId !== $second->id)
        ->andReturnUsing(fn ($job) => $dispatcher->dispatch($job));
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('first failed'));
    app(ProofChildStarts::class)->advance($first->refresh());

    expect($second->refresh()->status)->toBe(OperationStatus::Failed)->and($second->error_code)->toBe('swamp.start_enqueue_failed');
    expect(OperationRunBudgets::deadline($third->refresh()))->toBeNull();
    $this->travel(ProofRunWatch::AdmissionSeconds + 1)->seconds();
    expect(app(ProofRunWatch::class)->sweep())->toBe(1);
    expect($third->refresh()->error_code)->toBe('proof.admission_interrupted');
    expect($proof->refresh()->status)->toBe(OperationStatus::Failed);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $third->id);
});
