<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Proof\ProofBatchPlan;
use App\Domain\Proof\ProofChildStarts;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ProofCommandProjectionTest.php';

/** The runs of the projection fixture, each with its active copy, before native submission rather than after it. */
function proofPendingStarts(int $count = 3): array
{
    [$proof, $records] = proofRuns(array_slice(['firewall:allow', 'firewall:list', 'firewall:deny', 'node:list', 'route:list', 'task:list'], 0, $count));
    foreach ($records as [$child]) {
        $child->update(['status' => OperationStatus::Queued, 'state' => ['swamp_start' => [
            'key' => 'operation:'.$child->id, 'workflow' => 'operation-proof-run-copy',
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
    // The fixture has already captured its provision envelope; only the native start is under test. A second answer keeps the first wrapper.
    $projection = app(ProofCommandProjection::class);
    if (! $projection instanceof ProofCommandProjection) {
        return;
    }
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

it('queues every child at once and starts each without waiting for the slow answer of another', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    $began = now()->toImmutable();
    foreach ($records as [$child]) {
        Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $child->id
            && $job->queue === 'operation-starts' && $job->connection === 'gateway' && $job->delay === null);
    }
    proofStartAnswer(82);
    app()->call([new StartAdmittedSwampRun($first->id), 'handle']);
    $this->travelTo($began);
    app()->call([new StartAdmittedSwampRun($second->id), 'handle']);
    app()->call([new StartAdmittedSwampRun($first->id), 'handle']);

    expect($first->refresh()->status)->toBe(OperationStatus::Running)
        ->and($second->refresh()->status)->toBe(OperationStatus::Running)
        ->and($second->state['proof_child_start']['released_at'])->toBe($began->toISOString())
        ->and($third->refresh()->status)->toBe(OperationStatus::Queued);
    Queue::assertPushed(StartAdmittedSwampRun::class, 3);
});

it('starts the siblings after a definite job failure and cancels the failed run\'s group, which removes its copy, through its projection', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    $job = new StartAdmittedSwampRun($first->id);

    $job->failed(new RuntimeException('worker stopped before submission'));
    $job->failed(new RuntimeException('repeated failure callback'));
    app()->call([$job, 'handle']);

    expect($first->refresh()->status)->toBe(OperationStatus::Failed)->and($first->error_code)->toBe('swamp.start_job_failed');
    Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $first->id);
    proofStartAnswer();
    app()->call([new StartAdmittedSwampRun($second->id), 'handle']);
    expect($second->refresh()->status)->toBe(OperationStatus::Running);
    app()->call([new StartAdmittedSwampRun($third->id), 'handle']);
    Queue::assertPushed(StartAdmittedSwampRun::class, 3);
    expect($third->refresh()->status)->toBe(OperationStatus::Running);
    expect($first->refresh()->state)->not->toHaveKey('swamp_run_id');
});

it('starts the siblings after an explicit native refusal', function (): void {
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
    proofStartAnswer();
    app()->call([new StartAdmittedSwampRun($second->id), 'handle']);
    expect($second->refresh()->status)->toBe(OperationStatus::Running);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('never resubmits an uncertain start', function (): void {
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
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
    expect(OperationRunBudgets::deadline($second->refresh()))->toBeNull();
    $this->travel(601)->seconds();
    app(ExpireOperationAction::class)->execute($first);
    expect($first->refresh()->error_code)->toBe('operation.timed_out');
});

it('cancels deferred children through normal cancellation when the parent ends before their start', function (string $end): void {
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
    expect(fn () => app()->call([new StartAdmittedSwampRun($second->id), 'handle']))->not->toThrow(ResourceOperationException::class);

    foreach ([$second, $third] as $child) {
        expect($child->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($child->cancel_requested_at)->not->toBeNull()->and($child->lock_key)->toBeNull()->and($child->error_code)->toBeNull();
        Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $child->id);
    }
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

it('ends a child whose start is never delivered with the watchdog after a sibling enqueue fails', function (): void {
    $this->freezeSecond();
    Queue::fake();
    $dispatcher = Bus::getFacadeRoot();
    $starts = 0;
    // The queue write of the second start fails.
    Bus::shouldReceive('dispatch')->andReturnUsing(function ($job) use ($dispatcher, &$starts) {
        if ($job instanceof StartAdmittedSwampRun && ++$starts === 2) {
            throw new RuntimeException('queue unavailable');
        }

        return $dispatcher->dispatch($job);
    });
    [$proof, $records] = proofPendingStarts();
    [[$first], [$second], [$third]] = $records;
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('first failed'));

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

function proofUncertainStarts(): array
{
    [$proof, $records] = proofPendingStarts(2);
    $first = $records[0][0]->refresh();
    app(ProofChildStarts::class)->release($first);
    $first->update(['status' => OperationStatus::Running, 'started_at' => now()]);
    (new StartAdmittedSwampRun($first->id))->failed(new RuntimeException('confirmation lost'));

    return [$proof, $records];
}

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
                'runId' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run-copy', 'inputs' => $inputs,
                'tags' => ['orbit_start_key' => 'operation:'.$child->id],
            ]]]],
            'workflow.history.get' => ['data' => ['id' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run-copy', 'status' => 'running', 'inputs' => $inputs]],
        };

        return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => $payload]];
    });
    app()->instance(WebSocketClient::class, $socket);
}

it('starts a delayed child delivered at the database queues whole-second precision', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-13T12:00:00Z'));
    $nativeQueue = Queue::connection('gateway');
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-13T12:00:00.900000Z'));
    [, $records] = proofPendingStarts(2);
    [, [$second]] = $records;
    $job = Queue::pushed(StartAdmittedSwampRun::class)->first(fn ($job): bool => $job->operationId === $second->id);
    $id = $nativeQueue->later($job->delay, $job, queue: 'operation-starts');
    // Laravel uses getTimestamp(), discarding fractions, for the native queue's available_at.
    $this->travelTo(CarbonImmutable::createFromTimestamp($job->delay->getTimestamp())->addMicroseconds(100000));
    $delivered = $nativeQueue->pop('operation-starts');
    expect($delivered)->not->toBeNull();
    expect($delivered->getJobId())->toBe($id);
    proofStartAnswer();
    $delivered->fire();

    expect($second->refresh()->status)->toBe(OperationStatus::Running);
    expect($second->state['proof_child_start']['not_before'])->toBe('2026-11-13T12:00:01.000000Z');
    expect($job->delay->toISOString())->toBe($second->state['proof_child_start']['not_before']);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('requeues an early delivery at the stored due time and starts that child once when due', function (): void {
    $this->freezeSecond();
    Queue::fake();
    [, $records] = proofPendingStarts(2);
    [, [$second]] = $records;
    $due = now()->toImmutable()->addSeconds(20);
    app(OperationStore::class)->change($second->refresh(), ['state' => [...$second->state,
        'proof_child_start' => [...$second->state['proof_child_start'], 'not_before' => $due->toISOString()]]]);
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

describe('stale child start fence', function (): void {
    beforeEach(fn () => Carbon::setTestNow(now()->startOfSecond()));
    afterEach(fn () => Carbon::setTestNow());

    it('cancels an already queued child that became stale before handle without submitting it', function (): void {
        Queue::fake();
        proofPendingStarts(1);
        $proof = Operation::query()->where('kind', 'proof.run')->sole();
        $child = ProofRunSettlement::children($proof->id)->sole();
        $job = new StartAdmittedSwampRun($child->id);
        Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $queued): bool => $queued->operationId === $child->id);
        Carbon::setTestNow(now()->addSeconds(ProofBatchPlan::MaxShardSeconds + ExpireOperationAction::HoldMinutes * 60 + 1));
        Queue::fake();
        proofStartAnswer();
        $socket = Mockery::mock(WebSocketClient::class);
        $socket->shouldNotReceive('connect');
        app()->instance(WebSocketClient::class, $socket);

        expect(fn () => app()->call([$job, 'handle']))->not->toThrow(ResourceOperationException::class);

        expect($child->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($child->state)->not->toHaveKey('swamp_run_id');
        Queue::assertNotPushed(StartAdmittedSwampRun::class);
        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $child->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($proof->status->terminal())->toBeTrue()
            ->and($proof->error_code)->toBe('proof.run_failed')
            ->and($proof->result['outcome'] ?? null)->toBe('failed');
    });

    it('starts all six healthy held children together, so slow answers do not delay the last child', function (): void {
        Queue::fake();
        proofPendingStarts(6);
        $children = ProofRunSettlement::children(Operation::query()->where('kind', 'proof.run')->sole()->id);
        $began = now()->toImmutable();
        proofStartAnswer(120);
        foreach ($children as $child) {
            // Each job runs on its own worker at the same time; this test runs them one after another and turns the clock back.
            Carbon::setTestNow($began);
            app()->call([new StartAdmittedSwampRun($child->id), 'handle']);
            expect($child->refresh()->status)->toBe(OperationStatus::Running)
                ->and(isset($child->state['swamp_run_id']))->toBeTrue()
                ->and(ProofChildStarts::held($child))->toBeFalse()
                ->and($child->state['proof_child_start']['released_at'])->toBe($began->toISOString());
        }
        Queue::assertPushed(StartAdmittedSwampRun::class, 6);
        Queue::assertNotPushed(CancelProofGroup::class);
    });

});
