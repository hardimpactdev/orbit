<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Infrastructure\Processes\CommandDeadline;
use App\Jobs\ReadOperation;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\WaitingOperationKind;

beforeEach(function (): void {
    enable_operation_queue();
    $this->travelTo(now()->startOfSecond());
    $this->kind = new WaitingOperationKind;
    app()->instance(WaitingOperationKind::class, $this->kind);
    app()->instance(OperationKinds::class, new OperationKinds(app(), ['test.wait' => WaitingOperationKind::class]));
});

function waiting_operation(?int $node = null, int $target = 1): Operation
{
    $operation = app(OperationStore::class)->create('test.wait', 'instance', $target, $node, [], 'test', null);
    app(OperationRunner::class)->run($operation->id);

    return $operation->refresh();
}

function read_waiting_job(): void
{
    $job = Queue::connection(GatewayQueue::Connection)->pop(GatewayQueue::Operations->value);
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();
}

describe('waiting operations', function (): void {
    it('ends its job, keeps its target and current step, and schedules a delayed read', function (): void {
        $operation = waiting_operation();
        $step = $operation->steps()->where('name', 'external')->sole();

        expect($operation->status)->toBe(OperationStatus::Waiting)
            ->and($operation->worker)->toBeNull()
            ->and($operation->lock_key)->toBe('instance:1')
            ->and($operation->current_step)->toBe('external')
            ->and($operation->finished_at)->toBeNull()
            ->and($step->status)->toBe(OperationStepStatus::Waiting)
            ->and($step->sequence)->toBe($operation->sequence)
            ->and($step->finished_at)->toBeNull()
            ->and($operation->next_read_at->equalTo(now()->addSeconds(10)))->toBeTrue()
            ->and(Queue::connection(GatewayQueue::Connection)->delayedSize('operations'))->toBe(1);
    });

    it('reads repeatedly beyond the deadline without starting work again or spending attempts', function (): void {
        $clock = 0.0;
        $deadline = new CommandDeadline(static function () use (&$clock): float {
            return $clock;
        });
        app()->instance(CommandDeadline::class, $deadline);
        $operation = waiting_operation();
        $startedAt = $operation->steps()->where('name', 'external')->sole()->started_at;
        $clock = 2000.0;
        $this->travel(2000)->seconds();
        $budget = null;
        $this->kind->onRead = static function () use ($deadline, &$budget): void {
            $budget = $deadline->cap(2000);
        };
        for ($i = 0; $i < 4; $i++) {
            read_waiting_job();
            $this->travel(10)->seconds();
        }

        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting)
            ->and($operation->attempts)->toBe(1)
            ->and($this->kind->starts)->toBe(1)
            ->and($this->kind->reads)->toBe(4)
            ->and($budget)->toBe(1580.0)
            ->and($operation->steps()->where('name', 'external')->sole()->started_at->equalTo($startedAt))->toBeTrue()
            ->and($operation->steps()->where('name', 'external')->sole()->output)->toBe("started\nread\nread\nread\nread\n");

        $this->kind->done = true;
        read_waiting_job();

        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->attempts)->toBe(1)
            ->and($operation->result)->toBe(['external' => 'finished'])
            ->and($operation->lock_key)->toBeNull()
            ->and($this->kind->starts)->toBe(1)
            ->and($this->kind->after)->toBe(1);
    });

    it('claims one due read with a conditional update even from two stale copies', function (): void {
        $operation = waiting_operation();
        $copy = $operation->fresh();
        $this->travel(10)->seconds();

        expect(app(OperationStore::class)->claimWaiting($operation, 'first'))->toBeTrue()
            ->and(app(OperationStore::class)->claimWaiting($copy, 'second'))->toBeFalse()
            ->and($copy->status)->toBe(OperationStatus::Running)
            ->and($copy->worker)->toBe('first')
            ->and($copy->attempts)->toBe(1);
    });

    it('lets racing delayed jobs read once and rejects early and late copies', function (): void {
        $operation = waiting_operation();
        $runner = app(OperationRunner::class);
        new ReadOperation($operation->id)->handle($runner);
        expect($this->kind->reads)->toBe(0);
        $this->travel(10)->seconds();
        $this->kind->onRead = static function () use ($operation, $runner): void {
            new ReadOperation($operation->id)->handle($runner);
        };
        new ReadOperation($operation->id)->handle($runner);
        new ReadOperation($operation->id)->handle($runner);

        expect($this->kind->reads)->toBe(1)
            ->and($this->kind->starts)->toBe(1)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Waiting)
            ->and($operation->attempts)->toBe(1);
    });

    it('recovers a lost read by reading again even when starting the step is not resumable', function (): void {
        $operation = waiting_operation();
        DB::table('jobs')->delete();
        $this->travel(10)->seconds();
        app(OperationStore::class)->claimWaiting($operation, 'lost-reader');
        $operation->steps()->where('name', 'external')->update(['status' => OperationStepStatus::Running]);
        $this->kind->done = true;

        expect(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and(run_operation_jobs())->toBe(1)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($this->kind->starts)->toBe(1)
            ->and($this->kind->reads)->toBe(1);
    });

    it('stops a waiting step immediately and cancels the remaining steps', function (): void {
        $operation = waiting_operation();
        app(CancelOperationAction::class)->execute($operation);

        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($operation->lock_key)->toBeNull()
            ->and($operation->current_step)->toBeNull()
            ->and($operation->steps()->pluck('status')->all())->toBe([OperationStepStatus::Cancelled, OperationStepStatus::Cancelled])
            ->and($this->kind->stops)->toBe(1)
            ->and($this->kind->requestSeenAtStop)->toBeTrue()
            ->and($this->kind->after)->toBe(0);
        $this->travel(10)->seconds();
        read_waiting_job();
        expect($this->kind->reads)->toBe(0)->and($this->kind->stops)->toBe(1);
    });

    it('records an unconfirmed cancel before returning 502 and retries the stop on each read', function (): void {
        $fixture = Orb220DeploymentApiFixture::create();
        $operation = waiting_operation($fixture->owner->id, $fixture->instance->id);
        $this->kind->stopConfirmed = false;
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip])
            ->call('POST', "/api/v1/operations/{$operation->id}/cancel", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertStatus(502)->assertJsonPath('error.code', 'test.stop_unconfirmed');

        expect($operation->refresh()->cancel_requested_at)->not->toBeNull()
            ->and($this->kind->requestSeenAtStop)->toBeTrue()
            ->and($operation->status)->toBe(OperationStatus::Waiting)
            ->and($operation->lock_key)->toBe('instance:'.$fixture->instance->id)
            ->and($operation->steps()->where('name', 'external')->sole()->status)->toBe(OperationStepStatus::Waiting);
        $this->travel(10)->seconds();
        read_waiting_job();
        expect($operation->refresh()->status)->toBe(OperationStatus::Waiting)
            ->and($this->kind->stops)->toBe(2);
        $this->kind->stopConfirmed = true;
        $this->travel(10)->seconds();
        read_waiting_job();
        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($this->kind->stops)->toBe(3)
            ->and($this->kind->reads)->toBe(0)
            ->and($operation->attempts)->toBe(1);
    });

    it('returns waiting through the API and list filter and keeps a second start target_busy', function (): void {
        $fixture = Orb220DeploymentApiFixture::create();
        $operation = waiting_operation($fixture->owner->id, $fixture->instance->id);
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);
        $this->getJson("/api/v1/operations/{$operation->id}")->assertOk()
            ->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.steps.0.status', 'waiting');
        $this->getJson('/api/v1/operations?status=waiting')->assertOk()->assertJsonPath('data.0.id', $operation->id);
        $this->call('POST', "/api/v1/instances/{$fixture->instance->id}/deploy", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $operation->id);
    });
});

describe('waiting sweep rules', function (): void {
    it('skips a free waiting lease until more than twice the delay overdue and recovers the lost read once', function (): void {
        $operation = waiting_operation();
        DB::table('jobs')->delete();
        expect(app(OperationSweeper::class)->sweep())->toBe(0);
        $this->travel(30)->seconds();
        expect(app(OperationSweeper::class)->sweep())->toBe(0);
        $this->travel(1)->seconds();
        expect(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Waiting)
            ->and(app(OperationSweeper::class)->sweep())->toBe(0);
        read_waiting_job();
        expect($this->kind->reads)->toBe(1)->and($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    });

    it('recovers a stale queued start while a waiting read remains delayed or pending', function (int $delay): void {
        waiting_operation();
        DB::table('jobs')->delete();
        $queued = app(OperationStore::class)->create('test.wait', 'instance', 2, null, [], 'test', null);
        $queued->update(['dispatched_at' => now()->subSeconds(601)]);
        Queue::connection(GatewayQueue::Connection)->laterOn('operations', $delay, new ReadOperation(999));

        expect(app(OperationDispatcher::class)->waitingJobs())->toBe(0)
            ->and(app(OperationSweeper::class)->sweep())->toBe(1)
            ->and($queued->refresh()->status)->toBe(OperationStatus::Queued)
            ->and(app(OperationDispatcher::class)->waitingJobs())->toBe(1);
    })->with(['delayed' => 100, 'pending' => 0]);
});
