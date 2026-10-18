<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;

it('stops asking for assistance when a flagged task is saved as completed or cancelled', function (string $level, string $status): void {
    $gateway = Node::query()->create([
        'name' => 'ended-assistance-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = Project::query()->create([
        'name' => 'Ended assistance',
        'slug' => 'ended-assistance',
        'repository_url' => 'git@example.test:ended-assistance.git',
        'default_branch' => 'main',
    ]);
    $reason = 'The operator asked to hold this task.';
    $open = test_create_task(Task::topLevel(), [
        'project_id' => $project->id,
        'title' => 'Still blocked',
        'brief' => 'This one still asks.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Still blocked.',
    ]);
    $group = test_create_task(Task::topLevel(), [
        'project_id' => $project->id,
        'title' => 'Ending task',
        'brief' => 'This one ends.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => $level === 'group',
        'assistance_reason' => $level === 'group' ? $reason : null,
    ]);
    $subject = $group;
    if ($level === 'subtask') {
        $subject = test_create_task(Task::query(), [
            'parent_id' => $group->id,
            'position' => 1,
            'title' => 'Ending subtask',
            'brief' => 'This subtask ends.',
            'status' => TaskStatus::Running,
            'assistance_requested' => true,
            'assistance_reason' => $reason,
        ]);
    }

    $subject->status = $level === 'group' ? TaskGroupStatus::from($status) : TaskStatus::from($status);
    $subject->save();

    $subject->refresh();
    expect($subject->assistance_requested)->toBeFalse()
        ->and($subject->assistance_reason)->toBe($reason)
        ->and($subject->status->value)->toBe($status);

    TaskAssistance::apply($subject, AssistanceKind::Failure, null, $reason);

    expect($subject->fresh()?->assistance_requested)->toBeTrue()
        ->and($subject->fresh()?->assistance_reason)->toBe($reason);

    $response = $this->getJson('/api/v1/tasks/status')->assertOk();
    expect(array_column($response->json('data.assistance'), 'id'))->toBe($level === 'group' ? [$open->id, $group->id] : [$open->id]);
})->with([
    'completed group' => ['group', 'completed'],
    'cancelled group' => ['group', 'cancelled'],
    'completed subtask' => ['subtask', 'completed'],
    'cancelled subtask' => ['subtask', 'cancelled'],
]);

it('audits assistance clears when cancelling the remaining subtasks', function (): void {
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'Cancel remaining',
        'slug' => 'cancel-remaining',
        'repository_url' => 'git@example.test:cancel-remaining.git',
        'default_branch' => 'main',
    ]);
    $group = test_create_task(Task::topLevel(), [
        'project_id' => $project->id,
        'title' => 'Cancel the rest',
        'brief' => 'End the open subtasks.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'The group is blocked.',
    ]);
    $open = test_create_task(Task::query(), [
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Still open',
        'brief' => 'Waiting.',
        'status' => TaskStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Still waiting.',
    ]);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group->fresh() ?? $group);

    expect(Activity::query()->where('description', 'assistance cleared')->whereIn('subject_id', [$group->id, $open->id])->count())->toBe(2)->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->assistance_requested)->toBeFalse()
        ->and($cancelled->assistance_reason)->toBe('The group is blocked.')
        ->and($open->fresh()?->status)->toBe(TaskStatus::Cancelled)
        ->and($open->fresh()?->assistance_requested)->toBeFalse()
        ->and($open->fresh()?->assistance_reason)->toBe('Still waiting.');
});

it('shows a new assistance comment on an ended group in tasks status', function (string $status): void {
    $gateway = Node::query()->create([
        'name' => 'ended-comment-gateway-'.$status,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.83',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = Project::query()->create([
        'name' => 'Ended comment',
        'slug' => 'ended-comment-'.$status,
        'repository_url' => 'git@example.test:ended-comment.git',
        'default_branch' => 'main',
    ]);
    $open = test_create_task(Task::topLevel(), [
        'project_id' => $project->id,
        'title' => 'Still blocked',
        'brief' => 'This one still asks.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => true,
        'assistance_reason' => 'Still blocked.',
    ]);
    $group = test_create_task(Task::topLevel(), [
        'project_id' => $project->id,
        'title' => 'Ended group',
        'brief' => 'Already finished.',
        'status' => TaskGroupStatus::from($status),
        'assistance_requested' => false,
        'assistance_reason' => 'Earlier reason.',
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Open subtask',
        'brief' => 'Still running.',
        'status' => TaskStatus::Running,
    ]);
    $reason = 'The operator asked for help after the task ended.';

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested',
        'body' => $reason,
        'author' => 'operator',
    ]);

    $group->refresh();
    $task->refresh();
    expect($comment->body)->toBe($reason)
        ->and($group->status->value)->toBe($status)
        ->and($group->assistance_requested)->toBeTrue()
        ->and($group->assistance_reason)->toBe($reason)
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->assistance_requested)->toBeTrue()
        ->and($task->assistance_reason)->toBe($reason);

    $this->getJson('/api/v1/tasks/status')->assertOk()->assertJsonPath('data.assistance.1.id', $group->id);
})->with(['completed', 'cancelled']);

it('keeps a stale save from leaving an ended task asking for assistance', function (string $level, string $status, string $interleaving): void {
    $project = Project::query()->create([
        'name' => 'Stale assistance',
        'slug' => 'stale-assistance-'.$level.'-'.$status.'-'.$interleaving,
        'repository_url' => 'git@example.test:stale-assistance.git',
        'default_branch' => 'main',
    ]);
    $group = test_create_task(Task::topLevel(), [
        'project_id' => $project->id,
        'title' => 'Stale group',
        'brief' => 'Two copies of one row.',
        'status' => TaskGroupStatus::Running,
        'assistance_requested' => false,
    ]);
    $subject = $group;
    if ($level === 'subtask') {
        $subject = test_create_task(Task::query(), [
            'parent_id' => $group->id,
            'position' => 1,
            'title' => 'Stale subtask',
            'brief' => 'Two copies of one subtask.',
            'status' => TaskStatus::Running,
            'assistance_requested' => false,
        ]);
    }
    $finder = $level === 'group'
        ? static fn (int $id): Task => Task::topLevel()->findOrFail($id)
        : static fn (int $id): Task => Task::query()->findOrFail($id);
    $stale = $finder($subject->id);
    $other = $finder($subject->id);
    $endedStatus = $level === 'group' ? TaskGroupStatus::from($status) : TaskStatus::from($status);

    if ($interleaving === 'end') {
        test_update_task($other, [
            'assistance_requested' => true,
            'assistance_reason' => 'Flagged while it was open.',
        ]);
        $stale->status = $endedStatus;
        $stale->save();

        expect($stale->assistance_requested)->toBeFalse()
            ->and($stale->fresh()?->status->value)->toBe($status)
            ->and($stale->fresh()?->assistance_requested)->toBeFalse()
            ->and($stale->fresh()?->assistance_reason)->toBe('Flagged while it was open.');
    } else {
        $other->status = $endedStatus;
        TaskAssistance::apply($other, reason: 'Ended by the other writer.');
        $other->save();
        TaskAssistance::apply($stale, AssistanceKind::Failure, null, 'Asked too late.');

        expect($stale->assistance_requested)->toBeTrue()
            ->and($stale->status->value)->toBe($status)
            ->and($stale->fresh()?->status->value)->toBe($status)
            ->and($stale->fresh()?->assistance_requested)->toBeTrue()
            ->and($stale->fresh()?->assistance_reason)->toBe('Asked too late.');
    }
})->with([
    'group completed flagged then ended' => ['group', 'completed', 'end'],
    'group cancelled flagged then ended' => ['group', 'cancelled', 'end'],
    'group completed ended then flagged' => ['group', 'completed', 'flag'],
    'group cancelled ended then flagged' => ['group', 'cancelled', 'flag'],
    'subtask completed flagged then ended' => ['subtask', 'completed', 'end'],
    'subtask cancelled flagged then ended' => ['subtask', 'cancelled', 'end'],
    'subtask completed ended then flagged' => ['subtask', 'completed', 'flag'],
    'subtask cancelled ended then flagged' => ['subtask', 'cancelled', 'flag'],
]);
