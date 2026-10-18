<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\AgentThread;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeAgentDriver;

it('withdraws an obsolete queued verdict when an operator resolution starts a new review attempt', function (): void {
    tick_bind_defaults();
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $reviewer = AgentThread::query()->findOrFail($group->reviewer_agent_thread_id);
    $reviewer->update(['task_id' => $task->id]);
    $failure = test_assistance_attributes(AssistanceKind::Failure, null, 'Checks still failed after the reminder. '.TaskScheduler::WorkspaceChangedReminder);
    test_update_task($group, [...$failure, 'status' => TaskGroupStatus::Reviewing]);
    test_update_task($task, [
        ...$failure, 'status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt,
        'review_workspace_head' => str_repeat('a', 40), 'review_workspace_tree' => str_repeat('b', 40),
    ]);
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'stopped-review');
    $registry = new AgentDriverRegistry([$driver]);
    app()->instance(AgentDriverRegistry::class, $registry);
    app()->instance(AgentThreadObserver::class, new AgentThreadObserver($registry));
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'check', 'other-actor');
    expect(fn () => $leases->run($group, 'verdict', 'review-outcome:'.$task->id, static fn (): bool => true, taskId: $task->id))->toThrow(TaskWorkspaceWaiting::class);
    $obsolete = TaskWorkspaceLeaseRecord::query()->where('owner', 'review-outcome:'.$task->id)->sole();

    $attempt = $task->review_attempt;
    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Start a fresh review on the resolved workspace.', 'author' => 'operator',
    ]);

    expect($task->fresh()->review_attempt)->toBe($attempt + 1)
        ->and($task->fresh()->assistance_requested)->toBeFalse()
        ->and($driver->calls)->toBe([]);
    expect($holder->fresh()->status)->toBe('held');
    $leases->release($holder);

    $admitted = $leases->turn($group->fresh(['taskable']), TaskThreadRole::Reviewer, 'thread:'.$reviewer->id, $reviewer->id);

    expect($obsolete->fresh()->status)->toBe('cancelled')
        ->and($admitted->thread_id)->toBe($reviewer->id)
        ->and($leases->status($group)[0]['waiters'])->toBe([]);
});
