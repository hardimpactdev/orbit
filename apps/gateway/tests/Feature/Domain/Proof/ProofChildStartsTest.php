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
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    $this->freezeSecond();
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
    $this->freezeSecond();
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
    $this->freezeSecond();
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
    $this->freezeSecond();
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
    $this->freezeSecond();
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
    $this->freezeSecond();
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

it('ends an undelivered successor with the watchdog after an earlier enqueue fails', function (): void {
    $this->freezeSecond();
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
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $third->id);
    Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $third->id);
});

/** A released first child whose worker lost confirmation; the next child remains held. */
function proofUncertainStarts(): array
{
    [$proof, $records] = proofPendingStarts(2);
    $first = $records[0][0]->refresh();
    app(ProofChildStarts::class)->release($first);
    $first->update(['status' => OperationStatus::Running, 'started_at' => now()]);
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('confirmation lost'));

    return [$proof, $records];
}

/** Native recovery evidence, followed by a still-running history in the reconcile pass. */
function proofRecoveryAnswer(Operation $child): void
{
    config(['swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frame = [];
    $inputs = ['operation_id' => $child->id, 'node_id' => $child->node_id, 'kind' => $child->kind];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        expect($sent['type'])->toBeIn(['workflow.run.search', 'workflow.history.get']);
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame, $inputs, $child): array {
        $payload = match ($frame['type']) {
            'workflow.run.search' => ['total' => 1, 'data' => ['results' => [[
                'runId' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run', 'inputs' => $inputs,
                'tags' => ['orbit_start_key' => 'operation:'.$child->id],
            ]]]],
            'workflow.history.get' => ['data' => ['id' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run', 'status' => 'running', 'inputs' => $inputs]],
        };

        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => $payload]];
    });
    app()->instance(WebSocketClient::class, $socket);
}

it('starts a delayed child delivered at the database queues whole-second precision', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-13T12:00:00Z'));
    $nativeQueue = Queue::connection('gateway');
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [[$first], [$second]] = $records;
    $this->travelTo(CarbonImmutable::parse('2026-11-13T12:00:00.900000Z'));
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('first answered'));
    $job = Queue::pushed(StartAdmittedSwampRun::class)->first(fn ($job): bool => $job->operationId === $second->id);
    $id = $nativeQueue->later($job->delay, $job, queue: 'operations');
    // Laravel uses getTimestamp(), discarding fractions, for the native queue's available_at.
    $this->travelTo(CarbonImmutable::createFromTimestamp($job->delay->getTimestamp())->addMicroseconds(100000));
    $delivered = $nativeQueue->pop('operations');
    expect($delivered)->not->toBeNull();
    expect($delivered->getJobId())->toBe($id);
    proofStartAnswer();
    $delivered->fire();

    expect($second->refresh()->status)->toBe(OperationStatus::Running);
    expect($second->state['proof_child_start']['not_before'])->toBe('2026-11-13T12:00:21.000000Z');
    expect($job->delay->toISOString())->toBe($second->state['proof_child_start']['not_before']);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('resumes the siblings twenty seconds after recovery binds an uncertain start', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofUncertainStarts();
    [[$first], [$second]] = $records;
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    $this->travel(180)->seconds();
    proofRecoveryAnswer($first);
    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($first->refresh()->state['swamp_run_id'])->toBe(PROOF_RUN_ID);
    expect($first->status)->toBe(OperationStatus::Running);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay->equalTo(now()->addSeconds(20)));
    expect($first->state['proof_child_start']['advanced_at'])->toBe(now()->toISOString());
});

it('resumes the siblings twenty seconds after an uncertain start reaches its admission deadline', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofUncertainStarts();
    [[$first], [$second]] = $records;
    $this->travel(600)->seconds();
    app(ExpireOperationAction::class)->execute($first->refresh());

    expect($first->refresh()->error_code)->toBe('operation.timed_out');
    expect($first->status)->toBe(OperationStatus::Failed);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay->equalTo(now()->addSeconds(20)));
    expect($first->state)->not->toHaveKey('swamp_run_id');
});

it('advances once when recovery and the original start job both resolve the same child', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofUncertainStarts();
    [[$first], [$second]] = $records;
    $this->travel(180)->seconds();
    proofRecoveryAnswer($first);
    app(SwampCommandStep::class)->recoverUnbound($first->refresh());
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    $resolved = now()->toImmutable();
    $this->travel(10)->seconds();
    $job = new StartAdmittedSwampRun($first->id);
    app()->call([$job, 'handle']);
    $job->failed(new RuntimeException('late failure notification'));
    app(SwampCommandStep::class)->recoverUnbound($first->refresh());

    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay->equalTo($resolved->addSeconds(20)));
    expect($first->refresh()->state['proof_child_start']['advanced_at'])->toBe($resolved->toISOString());
});

it('resumes the siblings when cancellation ends an unstarted child without its start job running', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [[$first], [$second]] = $records;
    app(CancelOperationAction::class)->execute($first->refresh());

    expect($first->refresh()->status)->toBe(OperationStatus::Cancelled);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay->equalTo(now()->addSeconds(20)));
});

it('keeps committed resolution and cleanup when advancement fails and repairs it on the next pass', function (string $resolution): void {
    $this->freezeSecond();
    Queue::fake();
    [$proof, $records] = proofUncertainStarts();
    [[$first], [$second]] = $records;
    Log::spy();
    $armed = false;
    $failed = false;
    $otherCallback = false;
    $armResolution = true;
    Operation::updated(function (Operation $changed) use ($first, &$armResolution, &$armed): void {
        if ($armResolution && $changed->id === $first->id && ($changed->status->terminal() || isset($changed->state['swamp_run_id']))) {
            $armResolution = false;
            DB::afterCommit(function () use (&$armed): void {
                $armed = true;
            });
        }
    });
    DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($proof, &$armed, &$failed): void {
        if ($armed && str_contains($sql, 'from "operations"') && in_array($proof->id, $bindings, true)) {
            $armed = false;
            $failed = true;
            throw new RuntimeException('private database busy detail');
        }
    });
    if ($resolution === 'expiry') {
        $this->travel(600)->seconds();
    } else {
        $this->travel(180)->seconds();
        proofRecoveryAnswer($first);
    }
    try {
        expect(function () use ($resolution, $first, &$armed, &$otherCallback): void {
            DB::transaction(function () use ($resolution, $first, &$armed, &$otherCallback): void {
                if ($resolution === 'expiry') {
                    app(ExpireOperationAction::class)->execute($first->refresh());
                } else {
                    app(SwampCommandStep::class)->recoverUnbound($first->refresh());
                }
                DB::afterCommit(function () use (&$otherCallback): void {
                    $otherCallback = true;
                });
            });
        })->not->toThrow(RuntimeException::class);
    } finally {
        $armed = false;
    }

    expect($failed)->toBeTrue()->and($otherCallback)->toBeTrue();
    expect($first->refresh()->state['proof_child_start']['advanced_at'])->toBeNull();
    if ($resolution === 'expiry') {
        expect($first->status)->toBe(OperationStatus::Failed)->and($first->error_code)->toBe('operation.timed_out');
        Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $first->id);
    } else {
        expect($first->state['swamp_run_id'])->toBe(PROOF_RUN_ID);
    }
    Log::shouldHaveReceived('warning')->once()->with('Proof child starts could not be advanced', [
        'operation_id' => $first->id, 'exception_type' => RuntimeException::class,
    ]);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    $this->travel(60)->seconds();
    app(ProofRunWatch::class)->sweep();

    expect($first->refresh()->state['proof_child_start']['advanced_at'])->toBe(now()->toISOString());
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $second->id && $job->delay->equalTo(now()->addSeconds(20)));
})->with(['expiry', 'recovery']);

it('repairs a stored answer once while scheduled and uncertain children prevent another start', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    $first->refresh()->update(['status' => OperationStatus::Running, 'state' => [...$first->state, 'swamp_run_id' => PROOF_RUN_ID]]);
    app(ProofRunWatch::class)->sweep();
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    $noticed = now()->toISOString();
    $this->travel(5)->seconds();
    app(ProofRunWatch::class)->sweep();
    expect($first->refresh()->state['proof_child_start']['advanced_at'])->toBe($noticed);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    $this->travel(15)->seconds();
    app(ProofChildStarts::class)->release($second->refresh());
    $second->update(['status' => OperationStatus::Running, 'started_at' => now()]);
    (new StartAdmittedSwampRun($second->id))->failed(new RuntimeException('second confirmation lost'));
    app(ProofRunWatch::class)->sweep();
    app(ProofRunWatch::class)->sweep();

    expect($second->refresh()->error_code)->toBe('swamp.start_uncertain');
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $third->id);
    expect($third->refresh()->state['proof_child_start']['not_before'])->toBeNull();
});

it('requeues an early delivery at the stored due time and starts that child once when due', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [[$first], [$second]] = $records;
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('first answered'));
    $due = now()->toImmutable()->addSeconds(20);
    $this->travel(19)->seconds();
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect');
    app()->instance(WebSocketClient::class, $socket);
    app()->call([new StartAdmittedSwampRun($second->id), 'handle']);

    expect($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($second->state['proof_child_start']['released_at'])->toBeNull();
    Queue::assertPushed(StartAdmittedSwampRun::class, 3);
    $retry = Queue::pushed(StartAdmittedSwampRun::class)->last();
    expect($retry->operationId)->toBe($second->id)->and($retry->delay->equalTo($due))->toBeTrue();
    $this->travel(1)->seconds();
    proofStartAnswer();
    $native = app(WebSocketClient::class);
    app()->call([$retry, 'handle']);
    app()->call([$retry, 'handle']);

    expect($second->refresh()->status)->toBe(OperationStatus::Running)
        ->and($second->state['swamp_run_id'])->toBe(PROOF_RUN_ID);
    Queue::assertPushed(StartAdmittedSwampRun::class, 3);
    $native->shouldHaveReceived('send')->once();
});
