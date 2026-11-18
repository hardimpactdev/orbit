<?php

declare(strict_types=1);

use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    app(TaskExtensionState::class)->enable();
});

function doneCheckOf(Task $group): ?int
{
    return app(TaskGroupDone::class)->check($group->refresh())?->id;
}

it('finds the done check when the last subtask completed after a reviewer turn on exactly its commit', function (): void {
    $world = TaskDoneFixture::make();

    expect(doneCheckOf($world->group))->toBe($world->check->id);
});

it('is not done while a clause fails', function (Closure $break): void {
    $world = TaskDoneFixture::make();
    $break($world);

    expect(doneCheckOf($world->group))->toBeNull();
})->with([
    'the group has a pull request' => [fn (TaskDoneFixture $w) => $w->group->update(['pr_url' => 'https://github.com/example/trial/pull/7'])],
    'the group ended' => [fn (TaskDoneFixture $w) => $w->group->update(['status' => TaskGroupStatus::Cancelled])],
    'the group has no task copy' => [fn (TaskDoneFixture $w) => $w->group->update(['taskable_type' => null, 'taskable_id' => null])],
    'a subtask is still open' => [fn (TaskDoneFixture $w) => Task::query()->create(['parent_id' => $w->group->id, 'position' => 2, 'title' => 'More', 'brief' => 'More.', 'status' => TaskStatus::Todo])],
    'no subtask completed' => [fn (TaskDoneFixture $w) => Task::query()->whereKey($w->subtask->id)->update(['status' => TaskStatus::Failed->value])],
    'a newer handoff check exists' => [fn (TaskDoneFixture $w) => TaskDoneFixture::check($w->group, $w->subtask, str_repeat('c', 40), status: TaskCheckStatus::Failed)],
    'the check is no eligible candidate' => [fn (TaskDoneFixture $w) => Operation::query()->where('kind', OperationKinds::TasksCheck)->update(['status' => OperationStatus::Failed->value])],
    'the reviewer reviewed another commit' => [fn (TaskDoneFixture $w) => TaskDoneFixture::reviewerTurn($w->group, $w->subtask, $w->reviewer, str_repeat('c', 40), now()->subMinute())],
    'the reviewer turn asked for input' => [fn (TaskDoneFixture $w) => TaskDoneFixture::reviewerTurn($w->group, $w->subtask, $w->reviewer, TaskDoneFixture::Commit, now()->subMinute(), 'asking_for_input')],
    'the group has no reviewer thread' => [fn (TaskDoneFixture $w) => $w->group->update(['reviewer_agent_thread_id' => null])],
    'an earlier turn may still run' => [fn (TaskDoneFixture $w) => $w->turn->update(['result' => ['state' => 'done', 'ended' => false]])],
    'another Operation of the group runs' => [fn (TaskDoneFixture $w) => Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget,
        'target_id' => $w->group->id, 'node_id' => null, 'input' => ['subtask' => $w->subtask->id, 'thread_id' => 0], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1])],
]);

it('does not need the done check to have recorded pr_when_done', function (): void {
    $world = TaskDoneFixture::make(recorded: null);

    expect(doneCheckOf($world->group))->toBe($world->check->id);
});

it('refuses to complete a task copy\'s subtask while a copy turn of the group runs', function (): void {
    $world = TaskDoneFixture::make();
    Task::query()->whereKey($world->subtask->id)->update(['status' => TaskStatus::Running->value]);
    $world->turn->update(['status' => OperationStatus::Running, 'finished_at' => null, 'result' => null]);
    $complete = fn () => app(UpdateTaskAction::class)->execute($world->group->refresh(), $world->subtask->refresh(), new UpdateTaskData(null, null, null, status: TaskStatus::Completed));

    expect($complete)->toThrow(function (ResourceOperationException $exception) use ($world): void {
        expect($exception->errorCode)->toBe('tasks.copy_turn_unended')->and($exception->details['operation_id'] ?? null)->toBe($world->turn->id);
    });
    expect($world->subtask->refresh()->status)->toBe(TaskStatus::Running);

    $world->turn->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => ['state' => 'done', 'ended' => true]]);
    expect($complete()->status)->toBe(TaskStatus::Completed);
});

it('is not made done by a later write to the subtask, such as a comment, while the review never ended before the completion', function (): void {
    $world = TaskDoneFixture::make();
    Task::query()->whereKey($world->subtask->id)->update(['status' => TaskStatus::Running->value]);
    $world->turn->update(['status' => OperationStatus::Running, 'finished_at' => null, 'result' => null]);
    expect(fn () => app(UpdateTaskAction::class)->execute($world->group->refresh(), $world->subtask->refresh(), new UpdateTaskData(null, null, null, status: TaskStatus::Completed)))
        ->toThrow(ResourceOperationException::class, 'A turn still runs in the task copy');
    $world->turn->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => ['state' => 'done', 'ended' => true]]);
    // A comment on the subtask bumps its row, as TaskAssistance does.
    Task::query()->whereKey($world->subtask->id)->update(['updated_at' => now()->addHour()]);

    expect(doneCheckOf($world->group))->toBeNull();
});

it('takes a re-check of the same commit as the done check, and counts the reviewer turn on the earlier check of that commit', function (): void {
    $world = TaskDoneFixture::make();
    // A handoff re-check of a clean recorded HEAD makes no new commit: a new check row with the same commit.
    $recheck = TaskDoneFixture::check($world->group, $world->subtask, TaskDoneFixture::Commit);

    expect(doneCheckOf($world->group))->toBe($recheck->id)->and($recheck->id)->not->toBe($world->check->id);
});

it('does not count a reviewer turn on another commit than the re-check\'s', function (): void {
    $world = TaskDoneFixture::make();
    TaskDoneFixture::check($world->group, $world->subtask, str_repeat('c', 40));

    expect(doneCheckOf($world->group))->toBeNull();
});

describe('the reopen of the last completed subtask (docs/reference/tasks.md#reopen-the-last-subtask)', function (): void {
    function reopenSubtask(Task $group, Task $subtask): Task
    {
        return app(UpdateTaskAction::class)->execute($group->refresh(), $subtask->refresh(), new UpdateTaskData(null, null, null, status: TaskStatus::Running));
    }

    function reopenRefusal(Task $group, Task $subtask): string
    {
        try {
            reopenSubtask($group, $subtask);
        } catch (ResourceOperationException $error) {
            return $error->errorCode.' '.$error->status;
        }

        return 'reopened';
    }

    it('reopens the last completed subtask of an open copy group, records it, and leaves the group not done', function (): void {
        $world = TaskDoneFixture::make();
        // A later cancelled subtask is not the last one.
        Task::query()->create(['parent_id' => $world->group->id, 'position' => 2, 'title' => 'Dropped', 'brief' => 'Dropped.', 'status' => TaskStatus::Cancelled]);
        expect(doneCheckOf($world->group))->toBe($world->check->id);

        expect(reopenSubtask($world->group, $world->subtask)->status)->toBe(TaskStatus::Running)
            ->and(doneCheckOf($world->group))->toBeNull()
            ->and(($activity = Activity::query()->where('description', 'subtask reopened')->sole())->only(['subject_id', 'command']))
            ->toBe(['subject_id' => $world->subtask->id, 'command' => 'task:update'])
            ->and($activity->properties->all())->toBe(['group_id' => $world->group->id, 'old' => 'completed', 'new' => 'running']);
    });

    it('refuses any other reopen and leaves the subtask completed', function (Closure $break, string $refusal): void {
        $world = TaskDoneFixture::make();
        $subtask = $break($world) ?? $world->subtask;
        $before = $subtask->refresh()->status;

        expect(reopenRefusal($world->group, $subtask))->toBe($refusal)
            ->and($subtask->refresh()->status)->toBe($before)
            ->and(Activity::query()->where('description', 'subtask reopened')->exists())->toBeFalse();
    })->with([
        'a later subtask exists' => [function (TaskDoneFixture $w): null {
            Task::query()->create(['parent_id' => $w->group->id, 'position' => 2, 'title' => 'More', 'brief' => 'More.', 'status' => TaskStatus::Completed]);

            return null;
        }, 'tasks.status_move_refused 422'],
        'another subtask has not ended' => [function (TaskDoneFixture $w): Task {
            Task::query()->whereKey($w->subtask->id)->update(['status' => TaskStatus::Running->value]);

            return Task::query()->create(['parent_id' => $w->group->id, 'position' => 2, 'title' => 'More', 'brief' => 'More.', 'status' => TaskStatus::Completed]);
        }, 'tasks.status_move_refused 422'],
        'the subtask failed' => [function (TaskDoneFixture $w): null {
            Task::query()->whereKey($w->subtask->id)->update(['status' => TaskStatus::Failed->value]);

            return null;
        }, 'tasks.status_move_refused 422'],
        'the group ended' => [function (TaskDoneFixture $w): null {
            $w->group->update(['status' => TaskGroupStatus::Completed]);

            return null;
        }, 'tasks.status_move_refused 422'],
        'the group has no task copy' => [function (TaskDoneFixture $w): null {
            $w->group->update(['taskable_type' => null, 'taskable_id' => null]);

            return null;
        }, 'tasks.status_move_refused 422'],
        'a copy turn of the group runs' => [function (TaskDoneFixture $w): null {
            $w->turn->update(['status' => OperationStatus::Running, 'finished_at' => null, 'result' => null]);

            return null;
        }, 'tasks.copy_turn_unended 409'],
    ]);

    it('is not done again until a new check, a reviewer turn on it and a new completion', function (): void {
        $world = TaskDoneFixture::make();
        reopenSubtask($world->group, $world->subtask);
        $fix = str_repeat('e', 40);

        $check = TaskDoneFixture::check($world->group, $world->subtask, $fix);
        expect(doneCheckOf($world->group))->toBeNull();
        TaskDoneFixture::reviewerTurn($world->group, $world->subtask, $world->reviewer, $fix, now());
        expect(doneCheckOf($world->group))->toBeNull();

        $completed = app(UpdateTaskAction::class)->execute($world->group->refresh(), $world->subtask->refresh(), new UpdateTaskData(null, null, null, status: TaskStatus::Completed));
        expect($completed->status)->toBe(TaskStatus::Completed)
            ->and(doneCheckOf($world->group))->toBe($check->id);
    });
});
