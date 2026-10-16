<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\AgentThread;
use App\Models\Task;
use Tests\Support\FakeAgentDriver;

it('replays a queued implementer failure resolution once after the workspace holder releases', function (): void {
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $hold = TaskAssistance::attributes(AssistanceKind::Failure, null, 'The implementer needs a resolution.');
    $task->update($hold);
    $group->update($hold);
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'blocked-turn');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'publication', 'other-publication');

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Continue using the documented contract.', 'author' => 'operator',
    ]);

    expect($driver->calls)->toBe([]);
    expect($task->fresh()->pending_resolution_comment_id)->toBe($comment->id)
        ->and($task->fresh()->assistance_requested)->toBeTrue()
        ->and($task->fresh()->communication_failures)->toBe(0);
    expect($leases->status($group)[0]['waiters'])->toHaveCount(1);

    $leases->release($holder);
    app()->forgetInstance(TaskWorkspaceLease::class);
    app(TaskScheduler::class)->tick();

    expect($driver->calls)->toHaveCount(1)
        ->and($driver->calls[0]['operation'])->toBe('send')
        ->and($driver->calls[0]['message'])->toContain($comment->body)
        ->and($driver->calls[0]['key'])->toBe('tasks.resolution.'.$comment->id);
    expect($task->fresh()->pending_resolution_comment_id)->toBeNull()
        ->and($task->fresh()->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->fresh()->assistance_requested)->toBeFalse()
        ->and($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($driver->calls)->toHaveCount(1);
    expect($leases->status($group)[0]['holder']['thread_id'])->toBe($task->implementer_agent_thread_id);
});

it('withdraws a queued resolution when its request changes', function (string $replacementKind): void {
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $hold = TaskAssistance::attributes(AssistanceKind::Failure, null, 'Original failure.');
    $task->update($hold);
    $group->update($hold);
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'blocked-turn');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $leases = app(TaskWorkspaceLease::class);
    $holder = $leases->acquire($group, 'publication', 'other-publication');
    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Resolve the original failure.', 'author' => 'operator',
    ]);
    $replacement = TaskAssistance::attributes($replacementKind === 'direction' ? AssistanceKind::Direction : AssistanceKind::Failure, null, 'A different request.');
    $task->update($replacement);
    $group->update($replacement);
    if ($replacementKind === 'reviewing') {
        $task->update(['status' => TaskStatus::Reviewing]);
    }
    $leases->release($holder);

    app(TaskScheduler::class)->tick();

    expect($driver->calls)->toBe([]);
    expect($task->fresh()->pending_resolution_comment_id)->toBeNull()
        ->and($task->fresh()->assistance_requested)->toBeTrue()
        ->and($task->fresh()->assistance_reason)->toBe('A different request.');
    expect($leases->status($group))->toBe([]);
})->with(['failure', 'direction', 'reviewing']);

it('does not replay an old resolution that was stored before assistance was requested', function (): void {
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'blocked-turn');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'An older unrelated answer.', 'author' => 'operator',
    ]);
    $task->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'New failure.'));
    $group->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'New failure.'));

    app(TaskScheduler::class)->tick();

    expect($driver->calls)->toBe([]);
    expect($task->fresh()->pending_resolution_comment_id)->toBeNull()
        ->and($task->fresh()->assistance_requested)->toBeTrue()
        ->and($task->fresh()->resolution_delivered_comment_id)->toBeNull();
});

it('retries the same persisted failure resolution after an accepted send loses its reply', function (): void {
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $hold = TaskAssistance::attributes(AssistanceKind::Failure, null, 'A resolution is required.');
    $task->update($hold);
    $group->update($hold);
    $driver = new class('pi') extends FakeAgentDriver
    {
        public bool $loseReply = true;

        public function send(AgentThread $thread, string $message, ?string $key = null): void
        {
            parent::send($thread, $message, $key);
            if ($this->loseReply) {
                $this->loseReply = false;
                throw new AgentDriverException('The send was accepted but its reply was lost.');
            }
        }
    };
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'prior-turn');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Continue the existing work.', 'author' => 'operator',
    ]);
    expect($driver->calls)->toHaveCount(1);
    expect($task->fresh()->pending_resolution_comment_id)->toBe($comment->id)
        ->and($task->fresh()->assistance_requested)->toBeTrue();
    app()->forgetInstance(TaskWorkspaceLease::class);
    $driver->observation = null;
    expect(app(TaskWorkspaceLease::class)->retrying($task->implementerThread, 'tasks.resolution.'.$comment->id, $driver->calls[0]['message']))->toBeTrue();

    app(TaskScheduler::class)->tick();

    expect($driver->calls)->toHaveCount(2)
        ->and($driver->calls[1])->toBe($driver->calls[0]);
    expect($task->fresh()->pending_resolution_comment_id)->toBeNull()
        ->and($task->fresh()->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->fresh()->assistance_requested)->toBeFalse();
    expect(app(TaskWorkspaceLease::class)->status($group)[0]['holder']['thread_id'])->toBe($task->implementer_agent_thread_id);
});

it('withdraws a reserved resolution when the request changes before immediate delivery', function (): void {
    tick_bind_defaults();
    $group = tick_group();
    $task = $group->tasks()->firstOrFail();
    $task->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'Original failure.'));
    $group->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'Original failure.'));
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done, turnId: 'blocked-turn');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    Task::updated(static function (Task $updated) use ($task): void {
        if ($updated->id === $task->id && $updated->wasChanged('pending_resolution_comment_id')
            && $updated->pending_resolution_comment_id !== null) {
            Task::query()->whereKey($task->id)->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'A newer failure.'));
        }
    });

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Resolve only the original request.', 'author' => 'operator',
    ]);

    expect($driver->calls)->toBe([]);
    expect($task->fresh()->pending_resolution_comment_id)->toBeNull()
        ->and($task->fresh()->assistance_reason)->toBe('A newer failure.')
        ->and($task->fresh()->resolution_delivered_comment_id)->toBeNull();
});
