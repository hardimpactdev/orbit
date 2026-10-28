<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeTaskCheckRunner;

beforeEach(function (): void {
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
});

/** An earlier approval is published, but the later implementer and reviewer still hold work. */
function ended_pr_group(string $state, string $status = 'running'): Task
{
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::response($state === 'missing' ? [] : [
            ['number' => 42, 'html_url' => 'https://github.com/acme/orbit/pull/42', 'state' => 'closed',
                'merged_at' => $state === 'merged' ? '2026-10-08T10:00:00Z' : null],
        ]),
    ]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'ORB-152', 'slug' => 'orb-152',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);
    $group = test_create_task(Task::topLevel(), [
        'project_id' => $project->id, 'title' => 'Early merge', 'brief' => 'Keep the remaining work.',
        'status' => $status, 'assistance_requested' => true, 'assistance_reason' => 'An earlier question.',
        'pr_url' => $status === 'settling' ? 'https://github.com/acme/orbit/pull/42' : null,
    ]);
    $node = Node::query()->create([
        'name' => 'ended-pr-lease-node', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.91',
    ]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $group->id, 'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'ended-pr-workspace',
        'checkout_path' => '/srv/orbit/apps/ended-pr', 'branch' => 'task-'.$group->id, 'status' => 'source_resolved',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    foreach (['completed', 'running', 'reviewing', 'todo'] as $position => $taskStatus) {
        $task = Task::query()->create([
            'parent_id' => $group->id, 'position' => $position + 1,
            'title' => 'Work '.($position + 1), 'brief' => 'Do this work.', 'status' => $taskStatus,
        ]);
        if (! in_array($taskStatus, ['running', 'reviewing'], true)) {
            continue;
        }
        $role = $taskStatus === 'running' ? 'implementer' : 'reviewer';
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id,
            'driver' => 'pi', 'runtime_key' => 'test:'.$group->id,
            'external_id' => $role, 'role' => $role, 'state' => 'working',
        ]);
        if ($role === 'implementer') {
            $task->update(['implementer_agent_thread_id' => $thread->id]);
        } else {
            $group->update(['reviewer_agent_thread_id' => $thread->id]);
        }
    }

    return $group->fresh(['tasks']);
}

/** A driver boundary double records accepted keys, including a lost response after acceptance. */
function ended_pr_driver(): object
{
    $recording = (object) ['state' => AgentThreadState::Working, 'inputRequests' => [], 'calls' => [], 'accepted' => [], 'turns' => [], 'failure' => null];
    $driver = Mockery::mock(AgentDriver::class);
    $driver->shouldReceive('key')->andReturn('pi');
    $driver->shouldReceive('observe')->andReturnUsing(static fn (AgentThread $thread): AgentObservation => new AgentObservation($recording->state, turnId: $recording->turns[$thread->id] ?? 'initial-'.$thread->id, inputRequests: $recording->inputRequests));
    $driver->shouldReceive('interrupt')->never();
    $driver->shouldReceive('create')->never();
    $driver->shouldReceive('send')->andReturnUsing(static function (AgentThread $thread, string $message, ?string $key = null) use ($recording): void {
        $task = Task::query()->findOrFail($thread->task_id);
        expect($task->getAttribute('ended_pr_notice_state'))->toBe('pending')
            ->and($task->getAttribute('ended_pr_notice_key'))->toBe($key)
            ->and($task->getAttribute('ended_pr_notice_thread_id'))->toBe($thread->id);
        $recording->calls[] = ['thread' => $thread->id, 'message' => $message, 'key' => $key];
        if ($recording->failure === 'before') {
            throw new AgentDriverException('Send failed.');
        }
        $recording->accepted[$key] = $message;
        if ($recording->failure === 'after') {
            throw new AgentDriverException('Response lost after acceptance.');
        }
        $recording->turns[$thread->id] = $key;
        $recording->state = AgentThreadState::Done;
    });
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    return $recording;
}

it('preserves a current ended pull request reason when holding a resolution for a missing reviewer', function (bool $parentHold): void {
    $group = ended_pr_group('missing');
    $task = $group->tasks->firstWhere('status', TaskStatus::Reviewing);
    test_update_task($task, ['assistance_requested' => true, 'assistance_reason' => 'An earlier question.']);
    $task->load('parent');
    AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->delete();
    $driver = ended_pr_driver();
    $reason = 'Watched pull request ended: https://github.com/acme/orbit/pull/42 is closed. Open subtasks: #'.$task->id.' '.$task->title.'.';
    // The comment has been written using the old assistance snapshot. Another writer establishes
    // the hold before resolution eligibility and the reviewer lookup finish using that snapshot.
    TaskComment::created(static function (TaskComment $comment) use ($group, $task, $parentHold, $reason): void {
        if ($comment->type === TaskCommentType::Resolution) {
            test_update_task(($parentHold ? $group : $task)->fresh(), ['assistance_requested' => true, 'assistance_reason' => $reason]);
        }
    });

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Answer to the earlier question.', 'author' => 'operator',
    ]);

    expect(($parentHold ? $group : $task)->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($reason);
    expect($task->fresh())->assistance_requested->toBeTrue()->resolution_delivered_comment_id->toBeNull();
    expect($group->fresh())->assistance_requested->toBeTrue();
    expect($comment->fresh())->review_attempt->toBeNull();
    expect(Activity::query()->where('description', 'resolution held for reviewer')->count())->toBe(0);
    expect($driver->calls)->toBe([]);
})->with(['current parent reason' => true, 'current subtask reason' => false]);
