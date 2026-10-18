<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

function assistance_kind_group(): Task
{
    $project = Project::query()->create([
        'name' => 'Assistance',
        'slug' => 'assistance-kind',
        'repository_url' => 'git@example.test:assistance.git',
        'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Assistance',
        'brief' => 'Classify the request.',
        'status' => TaskGroupStatus::Running,
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Only',
        'brief' => 'One subtask.',
        'status' => TaskStatus::Running,
    ]);

    return $group->fresh(['tasks']) ?? $group;
}

it('stores an operator assistance comment as a direction request', function (): void {
    $group = assistance_kind_group();
    $task = $group->tasks->sole();
    $question = 'Which database should this subtask use?';

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested',
        'body' => $question,
        'author' => 'operator',
    ]);

    $task->refresh();
    $group->refresh();
    expect($task->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->assistance_question)->toBe($question)
        ->and($task->assistance_reason)->toBe($question)
        ->and($group->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->assistance_question)->toBe($question)
        ->and($group->assistance_reason)->toBe($question);
});

it('replaces an open failure with the operator direction request', function (): void {
    $group = assistance_kind_group();
    $task = $group->tasks->sole();
    $task->update(test_assistance_attributes(AssistanceKind::Failure, null, 'The implementer thread failed.'));
    $group->update(test_assistance_attributes(AssistanceKind::Failure, null, 'The implementer thread failed.'));
    $question = 'May I add the missing credential?';

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested',
        'body' => $question,
        'author' => 'operator',
    ]);

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question);
});

it('does not let a failure replace an open direction request', function (): void {
    $group = assistance_kind_group();
    $task = $group->tasks->sole();
    $question = 'Which database should this subtask use?';
    $reason = "The implementer is blocked: The mirror is down.\n\nQuestion: {$question}";
    $task->update(test_assistance_attributes(AssistanceKind::Direction, $question, $reason));
    $group->update(test_assistance_attributes(AssistanceKind::Direction, $question, $reason));

    expect(TaskAssistance::apply($task, AssistanceKind::Failure, null, 'The implementer thread failed.'))->toBeFalse()
        ->and(TaskAssistance::apply($group, AssistanceKind::Failure, null, 'Workspace removal failed: disk full', replaceFailure: true))->toBeFalse();
    app(RemoveTaskWorkspaceAction::class)->recordFailure($group->fresh() ?? $group, new RuntimeException('disk full'));

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($task->fresh()?->assistance_reason)->toBe($reason)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('does not let a stale failure writer replace a direction request or keep its question', function (): void {
    $group = assistance_kind_group();
    $stale = clone $group;
    $question = 'Which database should this subtask use?';
    $reason = "The implementer is blocked: The mirror is down.\n\nQuestion: {$question}";
    DB::table('tasks')->where('id', $group->id)->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction->value,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);

    expect(TaskAssistance::apply($stale, AssistanceKind::Failure, null, 'Workspace removal failed: disk full', replaceFailure: true))->toBeFalse();
    app(RemoveTaskWorkspaceAction::class)->recordFailure($stale, new RuntimeException('disk full'));

    expect($stale->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($stale->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('keeps the reason without asking when a stale assistance writer targets an ended task', function (string $level, string $status, AssistanceKind $kind): void {
    $group = assistance_kind_group();
    $record = $level === 'group' ? $group : $group->tasks->sole();
    $stale = clone $record;
    DB::table('tasks')->where('id', $record->id)->update(['status' => $status]);
    $reason = 'The request arrived after the task ended.';

    $applied = TaskAssistance::apply($stale, $kind, $kind === AssistanceKind::Direction ? 'Which mirror?' : null, $reason);

    expect($applied)->toBeTrue()
        ->and($stale->status->value)->toBe($status)
        ->and($stale->assistance_requested)->toBeFalse()
        ->and($stale->assistance_reason)->toBe($reason);
})->with(['group', 'subtask'])->with(['completed', 'cancelled'])->with([AssistanceKind::Direction, AssistanceKind::Failure]);

it('records each assistance change once in Activity', function (): void {
    $group = assistance_kind_group();
    $reason = 'The task VM could not prepare its topology.';

    TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason);
    TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);

    $entries = Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)
        ->where('description', 'assistance requested')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->sole()->properties?->get('kind'))->toBe('failure')
        ->and($entries->sole()->properties?->get('reason'))->toBe($reason)
        ->and($entries->sole()->properties?->get('task_group_id'))->toBe($group->id);
});

it('records resolution and keeps the earlier request in Activity', function (): void {
    $group = assistance_kind_group();
    TaskAssistance::apply($group, AssistanceKind::Direction, 'Which mirror?', 'Which mirror?');

    TaskAssistance::apply($group);
    TaskAssistance::apply($group);

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and(Activity::query()->where('subject_id', $group->id)->where('description', 'assistance requested')->count())->toBe(1)
        ->and(Activity::query()->where('subject_id', $group->id)->where('description', 'assistance cleared')->count())->toBe(1);
});

it('rolls back assistance and its Activity entry together', function (): void {
    $group = assistance_kind_group();
    try {
        DB::transaction(function () use ($group): void {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, 'The check failed.');
            throw new RuntimeException('Roll back the request.');
        });
    } catch (RuntimeException) {
    }

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Activity::query()->where('subject_id', $group->id)->where('description', 'assistance requested')->count())->toBe(0);
});

it('records lifecycle clears through the assistance rule and retains the last reason', function (): void {
    $group = assistance_kind_group();
    TaskAssistance::apply($group, AssistanceKind::Failure, null, 'Workspace removal failed.');

    $group->update(['status' => TaskGroupStatus::Cancelled]);

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBe('Workspace removal failed.')
        ->and(Activity::query()->where('subject_id', $group->id)->where('description', 'assistance cleared')->count())->toBe(1);
});

it('rolls back an ended status when its assistance Activity cannot be stored', function (): void {
    $group = assistance_kind_group();
    TaskAssistance::apply($group, AssistanceKind::Failure, null, 'Workspace removal failed.');
    Activity::creating(static function (Activity $entry) use ($group): void {
        if ($entry->subject_id === $group->id && $entry->description === 'assistance cleared') {
            throw new RuntimeException('Activity storage failed.');
        }
    });

    expect(fn () => $group->update(['status' => TaskGroupStatus::Cancelled]))->toThrow(RuntimeException::class, 'Activity storage failed.');
    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeTrue();
});

it('redacts assistance text before storing it in Activity', function (): void {
    $group = assistance_kind_group();
    TaskAssistance::apply($group, AssistanceKind::Direction, 'Use token=secret-value?', 'Failed with password=secret-value');

    $entry = Activity::query()->where('subject_id', $group->id)->where('description', 'assistance requested')->sole();
    expect(json_encode($entry->properties, JSON_THROW_ON_ERROR))->not->toContain('secret-value');
});

it('does not clear a reopened request when an ended model saves unrelated fields', function (): void {
    $group = assistance_kind_group();
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    $stale = $group;
    DB::table('tasks')->where('id', $group->id)->update(['status' => TaskGroupStatus::Running->value]);
    TaskAssistance::apply($group, AssistanceKind::Direction, 'Which mirror?', 'Choose the mirror.');

    $stale->update(['title' => 'Updated title']);

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe('Choose the mirror.')
        ->and(Activity::query()->where('subject_id', $group->id)->where('description', 'assistance cleared')->count())->toBe(0);
});
