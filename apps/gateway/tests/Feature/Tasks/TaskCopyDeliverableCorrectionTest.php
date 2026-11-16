<?php

declare(strict_types=1);

use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskCopyDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    app(TaskExtensionState::class)->enable();
});

/** @return list<array<string, string>> */
function wrongDeliverables(): array
{
    return [['id' => 'tools-tests', 'type' => 'command', 'description' => 'The tools tests pass', 'command' => 'python3 -m unittest tools/test_flows.py']];
}

/** @return list<array<string, string>> */
function rightDeliverables(): array
{
    return [['id' => 'tools-tests', 'type' => 'command', 'description' => 'The tools tests pass', 'command' => 'python3 -m unittest discover -s tools']];
}

/**
 * A task copy whose one subtask started and whose newest handoff check failed: the state a wrong deliverable leaves behind.
 *
 * @return array{TaskCopyDoneFixture, TaskCheck}
 */
function failedCopyCheck(): array
{
    $world = TaskCopyDoneFixture::make();
    $world->subtask->update(['status' => TaskStatus::Running, 'deliverables' => wrongDeliverables()]);
    $failed = TaskCopyDoneFixture::check($world->group, $world->subtask, str_repeat('c', 40), status: TaskCheckStatus::Failed);

    return [$world, $failed];
}

function correctDeliverables(Task $group, Task $subtask, ?string $reason = 'The tools tests need discover -s tools.', ?array $deliverables = null): Task
{
    return app(UpdateTaskAction::class)->execute($group, $subtask, new UpdateTaskData(null, null, null, deliverables: $deliverables ?? rightDeliverables(), reason: $reason));
}

function refusedCorrection(Closure $correct): ResourceOperationException
{
    try {
        $correct();
    } catch (ResourceOperationException $exception) {
        return $exception;
    }

    throw new RuntimeException('The correction was accepted.');
}

describe('a correction after a failed check in a task copy', function (): void {
    it('stores the new list and records the old list, the new list, the reason and the failed check in activity', function (): void {
        [$world, $failed] = failedCopyCheck();

        $task = correctDeliverables($world->group, $world->subtask);

        $activity = Activity::query()->where('subject_type', Task::class)->where('subject_id', $world->subtask->id)->where('description', 'deliverables corrected')->sole();
        expect($task->deliverables)->toBe(rightDeliverables())
            ->and($task->status)->toBe(TaskStatus::Running)
            ->and($task->copy_correction_check_id)->toBe($failed->id)
            ->and($activity->properties->all())->toBe(['check_id' => $failed->id, 'reason' => 'The tools tests need discover -s tools.', 'old' => wrongDeliverables(), 'new' => rightDeliverables()]);
    });

    it('lets the next handoff check run the new list', function (): void {
        SwampNodeFixture::ensure();
        [$world] = failedCopyCheck();

        correctDeliverables($world->group, $world->subtask);
        $intent = app(TaskCheckIntent::class)->capture($world->group->refresh(), new StartTaskCheckData($world->subtask->id, TaskCheckKind::Handoff));

        expect($intent['deliverables'])->toBe([[...rightDeliverables()[0], 'fails_on_base' => false]]);
    });

    it('takes one correction per failed check: a second before a new check is locked, and a new failed check takes one again', function (): void {
        [$world] = failedCopyCheck();
        correctDeliverables($world->group, $world->subtask);

        $refused = refusedCorrection(fn (): Task => correctDeliverables($world->group, $world->subtask, deliverables: wrongDeliverables()));
        expect($refused->errorCode)->toBe('tasks.deliverables_locked')
            ->and($world->subtask->refresh()->deliverables)->toBe(rightDeliverables());

        $next = TaskCopyDoneFixture::check($world->group, $world->subtask, str_repeat('d', 40), status: TaskCheckStatus::Failed);
        expect(correctDeliverables($world->group, $world->subtask, deliverables: wrongDeliverables())->copy_correction_check_id)->toBe($next->id);
    });

    it('needs a reason', function (): void {
        [$world] = failedCopyCheck();

        expect(fn (): Task => correctDeliverables($world->group, $world->subtask, reason: null))->toThrow(ValidationException::class)
            ->and($world->subtask->refresh()->deliverables)->toBe(wrongDeliverables())
            ->and(Activity::query()->where('description', 'deliverables corrected')->exists())->toBeFalse();
    });

    it('is locked while the clause it needs fails', function (Closure $break): void {
        [$world] = failedCopyCheck();
        $break($world);

        $refused = refusedCorrection(fn (): Task => correctDeliverables($world->group->refresh(), $world->subtask->refresh()));

        expect($refused->errorCode)->toBe('tasks.deliverables_locked')
            ->and($world->subtask->refresh()->deliverables)->toBe(wrongDeliverables())
            ->and(Activity::query()->where('description', 'deliverables corrected')->exists())->toBeFalse();
    })->with([
        'before any check' => [fn (TaskCopyDoneFixture $world) => TaskCheck::query()->where('task_id', $world->subtask->id)->delete()],
        'after a passed newest check' => [fn (TaskCopyDoneFixture $world) => TaskCopyDoneFixture::check($world->group, $world->subtask, str_repeat('e', 40))],
        'while the newest check runs' => [fn (TaskCopyDoneFixture $world) => TaskCopyDoneFixture::check($world->group, $world->subtask, str_repeat('e', 40), status: TaskCheckStatus::Running)],
        'on a subtask that is not current' => [fn (TaskCopyDoneFixture $world) => Task::query()->create(['parent_id' => $world->group->id, 'position' => 0, 'title' => 'Earlier', 'brief' => 'Earlier.',
            'status' => TaskStatus::Todo, 'deliverables' => rightDeliverables()])],
        'in a group without a task copy' => [fn (TaskCopyDoneFixture $world) => $world->instance->update(['task_workspace_owner_id' => null])],
    ]);

    it('refuses a reason on any other update and changes nothing', function (): void {
        [$world] = failedCopyCheck();
        $todo = Task::query()->create(['parent_id' => $world->group->id, 'position' => 2, 'title' => 'Next', 'brief' => 'Next.', 'status' => TaskStatus::Todo, 'deliverables' => wrongDeliverables()]);

        $planned = refusedCorrection(fn (): Task => correctDeliverables($world->group, $todo));
        $statusOnly = refusedCorrection(fn (): Task => app(UpdateTaskAction::class)->execute($world->group, $world->subtask,
            new UpdateTaskData(null, null, null, status: TaskStatus::Reviewing, reason: 'No reason here.')));

        expect($planned->errorCode)->toBe('tasks.reason_unexpected')->and($planned->status)->toBe(422)
            ->and($statusOnly->errorCode)->toBe('tasks.reason_unexpected')
            ->and($todo->refresh()->deliverables)->toBe(wrongDeliverables())
            ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Running);
    });
});
