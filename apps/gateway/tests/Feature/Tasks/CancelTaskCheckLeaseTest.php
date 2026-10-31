<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\Task;
use App\Models\TaskCheck;
use Tests\Support\TaskOperationFixture;

beforeEach(function (): void {
    TaskOperationFixture::bindDefaults();
    app(TaskExtensionState::class)->enable();
});

it('keeps cancellation under the execution lock and retains running ownership until remote termination', function (): void {
    $group = TaskOperationFixture::create();
    $task = $group->tasks()->firstOrFail();
    $check = cancel_lease_check($task);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'check:'.$task->id);
    $holder->update(['pid' => $check->pid, 'process_started' => $check->process_started]);
    $lock = new class implements TaskExecutionLock
    {
        /** @var list<int> */
        public array $held = [];

        public function synchronized(int $groupId, Closure $operation, bool $wait = true): mixed
        {
            $this->held[] = $groupId;
            try {
                return $operation();
            } finally {
                array_pop($this->held);
            }
        }
    };
    app()->instance(TaskExecutionLock::class, $lock);
    $runner = Mockery::mock(TaskCheckRunner::class);
    $runner->shouldReceive('cancel')->once()->andReturnUsing(function () use ($group, $check, $lock, $leases): void {
        expect($lock->held)->toContain($group->id);
        expect($check->fresh()->status)->toBe(TaskCheckStatus::Running);
        expect(fn () => $leases->acquire($group, 'reviewer', 'reviewer:next'))->toThrow(TaskWorkspaceWaiting::class);
    });
    $runner->shouldReceive('read')->once()->andReturnUsing(function () use ($group, $check, $lock): TaskCheckReading {
        expect($lock->held)->toContain($group->id);
        expect($check->fresh()->status)->toBe(TaskCheckStatus::Running);

        return TaskCheckReading::finished(143, str_repeat('a', 40), str_repeat('b', 40), [], 'The cancelled check finished.');
    });
    app()->instance(TaskCheckRunner::class, $runner);

    $cancelled = app(CancelTaskCheckAction::class)->execute($group, $task);

    expect($cancelled->status)->toBe(TaskCheckStatus::Cancelled);
    expect($cancelled->finished_at)->not->toBeNull();
});

it('keeps the check running when remote cancellation cannot prove termination', function (string $failure): void {
    $group = TaskOperationFixture::create();
    $task = $group->tasks()->firstOrFail();
    $check = cancel_lease_check($task);
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'check:'.$task->id);
    $holder->update(['pid' => $check->pid, 'process_started' => $check->process_started]);
    $runner = Mockery::mock(TaskCheckRunner::class);
    if ($failure === 'cancel unreachable') {
        $runner->shouldReceive('cancel')->once()->andThrow(new TaskCheckException('The Node is unreachable.'));
        $runner->shouldNotReceive('read');
    } else {
        $runner->shouldReceive('cancel')->once();
        if ($failure === 'read unreachable') {
            $runner->shouldReceive('read')->once()->andThrow(new TaskCheckException('The Node is unreachable.'));
        } elseif ($failure === 'supervisor lost') {
            $runner->shouldReceive('read')->once()->andReturn(TaskCheckReading::lost('The supervisor is gone without a result.'));
        } else {
            $runner->shouldReceive('read')->once()->andReturn(TaskCheckReading::running());
        }
    }
    app()->instance(TaskCheckRunner::class, $runner);

    expect(fn () => app(CancelTaskCheckAction::class)->execute($group, $task))->toThrow(ResourceOperationException::class);

    expect($check->fresh()->status)->toBe(TaskCheckStatus::Running);
    expect($check->fresh()->finished_at)->toBeNull();
    expect($holder->fresh()->status)->toBe('held');
    expect(fn () => $leases->acquire($group, 'reviewer', 'reviewer:next'))->toThrow(TaskWorkspaceWaiting::class);
})->with(['cancel unreachable', 'read unreachable', 'still running', 'supervisor lost']);

function cancel_lease_check(Task $task): TaskCheck
{
    return TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
        'pid' => 4001, 'process_started' => 'Wed Sep 23 12:00:01 2026',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
}
