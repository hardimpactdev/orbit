<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskCopyDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
});

function doneCheckOf(Task $group): ?int
{
    return app(TaskGroupDone::class)->check($group->refresh())?->id;
}

it('finds the done check when the last subtask completed after a reviewer turn on exactly its commit', function (): void {
    $world = TaskCopyDoneFixture::make();

    expect(doneCheckOf($world->group))->toBe($world->check->id);
});

it('is not done while a clause fails', function (Closure $break): void {
    $world = TaskCopyDoneFixture::make();
    $break($world);

    expect(doneCheckOf($world->group))->toBeNull();
})->with([
    'the group has a pull request' => [fn (TaskCopyDoneFixture $w) => $w->group->update(['pr_url' => 'https://github.com/example/trial/pull/7'])],
    'the group ended' => [fn (TaskCopyDoneFixture $w) => $w->group->update(['status' => TaskGroupStatus::Cancelled])],
    'the group has no task copy' => [fn (TaskCopyDoneFixture $w) => $w->group->update(['taskable_type' => null, 'taskable_id' => null])],
    'a subtask is still open' => [fn (TaskCopyDoneFixture $w) => Task::query()->create(['parent_id' => $w->group->id, 'position' => 2, 'title' => 'More', 'brief' => 'More.', 'status' => TaskStatus::Todo])],
    'no subtask completed' => [fn (TaskCopyDoneFixture $w) => Task::query()->whereKey($w->subtask->id)->update(['status' => TaskStatus::Failed->value])],
    'a newer handoff check exists' => [fn (TaskCopyDoneFixture $w) => TaskCopyDoneFixture::check($w->group, $w->subtask, str_repeat('c', 40), status: TaskCheckStatus::Failed)],
    'the check is no eligible candidate' => [fn (TaskCopyDoneFixture $w) => Operation::query()->where('kind', OperationKinds::TasksCheck)->update(['status' => OperationStatus::Failed->value])],
    'the reviewer reviewed another commit' => [fn (TaskCopyDoneFixture $w) => TaskCopyDoneFixture::reviewerTurn($w->group, $w->subtask, $w->reviewer, str_repeat('c', 40), now()->subMinute())],
    'the reviewer turn asked for input' => [fn (TaskCopyDoneFixture $w) => TaskCopyDoneFixture::reviewerTurn($w->group, $w->subtask, $w->reviewer, TaskCopyDoneFixture::Commit, now()->subMinute(), 'asking_for_input')],
    'the reviewer turn ended after the completion' => [fn (TaskCopyDoneFixture $w) => $w->turn->update(['finished_at' => now()->addMinute()])],
    'the group has no reviewer thread' => [fn (TaskCopyDoneFixture $w) => $w->group->update(['reviewer_agent_thread_id' => null])],
    'an earlier turn may still run' => [fn (TaskCopyDoneFixture $w) => $w->turn->update(['result' => ['state' => 'done', 'ended' => false]])],
    'another Operation of the group runs' => [fn (TaskCopyDoneFixture $w) => Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget,
        'target_id' => $w->group->id, 'node_id' => null, 'input' => ['subtask' => $w->subtask->id, 'thread_id' => 0], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1])],
]);

it('does not need the done check to have recorded pr_when_done', function (): void {
    $world = TaskCopyDoneFixture::make(recorded: null);

    expect(doneCheckOf($world->group))->toBe($world->check->id);
});
