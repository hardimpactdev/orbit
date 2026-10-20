<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\PrunePendingTaskThreads;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBranchUpdate;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGitHubReviewConsumption;
use App\Domain\Tasks\TaskGitHubReviewFeedback;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestDescription;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskReviewFindingsPacket;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskSettlingFixup;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\JevDecision;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Classification;
use Tests\Feature\Domain\Tasks\ApprovalObservationFixtures as FeedbackFixtures;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskPullRequestReviewWatcher;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\NullTaskWorkspaceDiffReader;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    tick_bind_defaults();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'done'],
                'latestTurn' => ['id' => 'settled-'.$threadId, 'state' => 'completed'],
            ]];
        }
    });
});

it('writes and reads real transcript observations only in its temporary archive', function (): void {
    $group = tick_group();
    $thread = test_agent_thread($group, 'archive-regression');
    $driver = new FakeAgentDriver('pi');
    $driver->observation = new AgentObservation(AgentThreadState::Done);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $archive = app(ArchiveAgentTranscriptsAction::class);

    $archive->execute($group->id, $thread->node_id);

    Storage::disk('local')->assertExists(ArchiveAgentTranscriptsAction::path($thread));
    expect($archive->read($thread))->toBe(app(AgentDriverRegistry::class)->get($thread->driver)->observe($thread)->toArray());
});

it('deletes stale pending thread rows for finished tasks', function (): void {
    $group = tick_group();
    $task = $group->tasks->firstOrFail();
    $task->update(['status' => TaskStatus::Completed]);
    $pending = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 't3',
        'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'pending:stale',
        'role' => 'reviewer', 'node_id' => $group->taskable->node_id,
    ]);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect(AgentThread::query()->find($pending->id))->toBeNull();
});

it('retains pending reservations for active tasks so spawn retries can reuse them', function (): void {
    $group = tick_group();
    $task = $group->tasks->firstOrFail();
    $task->update(['status' => TaskStatus::Running]);
    $pending = AgentThread::query()->create([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'driver' => 't3',
        'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'pending:active-reservation',
        'role' => 'implementer', 'node_id' => $group->taskable->node_id,
    ]);
    $pending->forceFill(['created_at' => now()->subDays(10)])->save();

    app(PrunePendingTaskThreads::class)->run();

    expect($pending->fresh())->not->toBeNull();
});

it('flags a prior settling group without a reviewed PR once and retains its workspace', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();

    Http::preventStrayRequests();

    app(TaskScheduler::class)->settle($group);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $group->id, 'status' => 'settling', 'pr_url' => null,
        'assistance_requested' => true, 'taskable_id' => $group->taskable_id, 'settled_at' => null,
    ]);
    Http::assertNothingSent();
});

it('resumes a settling group without a pull request and opens the pull request when that subtask is approved', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $reason = TaskScheduler::MissingPullRequestPrefix.' Cancel the group to push its approved commits to task-'.$group->id.' and remove its workspace.';
    test_update_task($group, [
        'status' => TaskGroupStatus::Settling,
        'pr_url' => null,
        'assistance_requested' => true,
        'assistance_reason' => $reason,
    ]);
    $task->update(['status' => TaskStatus::Todo, 'started_at' => null]);
    app(TaskExtensionState::class)->enable();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->pr_url)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and(tick_assistance_reasons())->toBe(['The settling group has no reviewed pull request URL. Cancel the group to push its approved commits to task-1 and remove its workspace.'])
        ->and($agents->spawned)->toBe([$task->id])
        ->and($agents->missingRefOk)->toBeTrue()
        ->and($agents->fastForwards)->toBe(1);

    $task->refresh();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $task->review_attempt,
        'review_notified_turn_id' => 'handoff-turn',
        ...tick_review_baseline(),
    ]);
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });
    tick_workspace(branch: 'task-'.$group->id);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([tick_final_approval()]));
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public function commit(Instance $instance, string $message, bool $allowEmpty = false): ?string
        {
            $this->messages[] = $message;
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = str_repeat('c', 40);
            }

            return str_repeat('c', 40);
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publishing->publisher->pushes)->toBe([$group->id])
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskTurnPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 1, $group->project->taskCheckCommand())])
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('does not resume a settling group without a pull request while another assistance cause is set', function (): void {
    $group = tick_group();
    test_update_task($group, [
        'status' => TaskGroupStatus::Settling,
        'assistance_requested' => true,
        'assistance_reason' => 'Workspace removal failed: disk full',
    ]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    $todo = Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Waiting', 'brief' => 'Stay todo.', 'status' => TaskStatus::Todo,
        'deliverables' => [[
            'id' => 'composer-check', 'type' => 'command', 'description' => 'Run composer check',
            'command' => 'composer check', 'directory' => '.',
        ]],
    ]);
    app(TaskExtensionState::class)->enable();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe('Workspace removal failed: disk full')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([])
        ->and($agents->fastForwards)->toBe(0);
});

it('continues watching a prior settling PR and completes only after it merges', function (): void {
    $group = tick_group();
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open'])
            ->push(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'pr_url' => $group->pr_url]);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'pr_url' => $group->pr_url, 'taskable_id' => null]);
    $this->assertDatabaseMissing('instances', ['id' => $group->taskable_id]);
    expect(tick_vm_destroyed())->toBe(['tvm-'.$group->id]);
    Http::assertSentCount(6);
});

it('preserves unresolved review assistance while CI and conflict fixes activate and continue', function (bool $conflict): void {
    mock(TaskPullRequestUpdater::class)->shouldReceive('updateBranch')->andReturn(TaskBranchUpdate::Conflict);
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    $source->candidateStatus = TaskReviewReadStatus::Unreadable;
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    $reason = 'The trusted review source remains unreadable (review:101:findings). Restore review-read access.';
    app(TaskGitHubReviewFeedback::class)->change($group, ['review:101:findings' => $reason]);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(new TaskPullRequestHealth(
        'open', ['Repair needed'], baseRef: 'main', conflicts: $conflict,
        failedChecks: $conflict ? [] : [new TaskPullRequestCheck('Gateway', null)], headSha: 'abc123',
    ));
    $agents = tick_running_agents();
    $snapshots = new class implements AgentSnapshotReader
    {
        public int $reads = 0;

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->reads++;

            return tick_checked_thread($threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? 'done' : 'running');
        }
    };
    app()->instance(AgentSnapshotReader::class, $snapshots);
    app(TaskScheduler::class)->tick();
    $fixup = $group->tasks()->whereNotNull('fixup_problem')->sole();
    expect($fixup->status)->toBe(TaskStatus::Running)
        ->and($agents->spawned)->toBe([$fixup->id])
        ->and($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toBe(TaskGitHubReviewFeedback::Prefix.$reason);
    app(TaskScheduler::class)->tick();
    expect($snapshots->reads)->toBeGreaterThan(0)
        ->and($group->fresh()->assistance_reason)->toBe(TaskGitHubReviewFeedback::Prefix.$reason)
        ->and($fixup->fresh()->status)->toBe(TaskStatus::Running)
        ->and(app(TaskGitHubReviewFeedback::class)->causes($group))->toBe(['review:101:findings' => $reason]);
})->with(['CI' => false, 'conflict' => true]);

it('starts one feedback fixup after a workspace retry and preserves its consumption through restart', function (): void {
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(new TaskPullRequestHealth('open', headSha: 'abc123'));
    $failedAgents = tick_running_agents(fetchFails: true);
    app(TaskScheduler::class)->tick();
    $fixup = $group->tasks()->where('fixup_problem', 'review:42')->sole();
    expect($fixup->status)->toBe(TaskStatus::Todo)->and($failedAgents->spawned)->toBe([]);
    $this->travel(60)->seconds();
    $agents = tick_running_agents();
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();
    expect($fixup->fresh()->status)->toBe(TaskStatus::Running)
        ->and($agents->spawned)->toBe([$fixup->id])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1)
        ->and($source->candidates)->toBe(1);
});

it('recovers a crash after feedback activation without consuming or appending again', function (): void {
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(new TaskPullRequestHealth('open', headSha: 'abc123'));
    tick_running_agents();
    mock(AgentSpawner::class)->shouldReceive('spawnImplementer')->once()->andThrow(new RuntimeException('Fixture crash after activation.'));
    test_tick_reporting(RuntimeException::class, 'Fixture crash after activation.');
    $fixup = $group->tasks()->where('fixup_problem', 'review:42')->sole();
    expect($fixup->status)->toBe(TaskStatus::Running)
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    $agents = tick_running_agents();
    app(TaskScheduler::class)->tick();
    expect($agents->spawned)->toBe([$fixup->id])
        ->and($source->candidates)->toBe(1)
        ->and($group->tasks()->where('fixup_problem', 'review:42')->count())->toBe(1);
});

it('retains consumption but skips feedback spawning when the PR closes after append', function (): void {
    $group = tick_settling_group();
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn(
        new TaskPullRequestHealth('open', headSha: 'abc123'),
        new TaskPullRequestHealth('closed', headSha: 'abc123'),
    );
    $agents = tick_running_agents();
    app(TaskScheduler::class)->tick();
    expect($group->tasks()->where('fixup_problem', 'review:42')->sole()->status)->toBe(TaskStatus::Cancelled)
        ->and($agents->spawned)->toBe([])
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('publishes a consumed feedback fixup once to the same PR and requires fresh external re-review', function (): void {
    [$group, $task, , $signer, $publisher] = tick_review([
        FakeTaskTurnReceipts::contents('approved', deliverables: ['review-findings' => 'Every immutable finding was addressed.']),
    ], last: true);
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git', 'task_check' => null]);
    $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/42']);
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $source = new FakeTaskPullRequestReviewWatcher(FeedbackFixtures::observation([FeedbackFixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    $candidate = $source->reviewCandidate($group, 101)->candidate;
    expect($candidate)->not->toBeNull();
    $plan = TaskSettlingFixup::reviewPlan(TaskReviewFindingsPacket::fromCandidate($candidate));
    $task->update(['fixup_problem' => $plan->identity, 'fixup_head_sha' => 'abc123', 'brief' => $plan->brief, 'deliverables' => $plan->deliverables]);
    DB::transaction(fn () => app(TaskGitHubReviewConsumption::class)->record($group, $task, $candidate, $group->tasks()->get()));
    $watcher = mock(TaskPullRequestWatcher::class);
    $watcher->shouldReceive('status')->andReturn('open');
    $watcher->shouldReceive('health')->andReturn(new TaskPullRequestHealth('open', headSha: str_repeat('c', 40)));
    $publisher->pushFailures = 1;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($signer->messages)->toHaveCount(1)
        ->and($task->comments()->sole()->commit_sha)->toBe(str_repeat('c', 40));
    $this->travel(60)->seconds();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($signer->messages)->toHaveCount(1)
        ->and($publisher->bodies)->toBe([])
        ->and($publisher->pushes)->toBe([$group->id, $group->id])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
    app(TaskScheduler::class)->tick();
    expect(DB::table('task_github_review_consumptions')->count())->toBe(1);
    $source->observation = FeedbackFixtures::observation([FeedbackFixtures::review(102, head: str_repeat('c', 40))], head: str_repeat('c', 40));
    app(TaskScheduler::class)->tick();
    expect($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    $source->observation = FeedbackFixtures::observation([FeedbackFixtures::review(103, GitHubReviewState::ChangesRequested, str_repeat('c', 40))], head: str_repeat('c', 40));
    tick_running_agents();
    app(TaskScheduler::class)->tick();
    expect(DB::table('task_github_review_consumptions')->count())->toBe(2)
        ->and($group->tasks()->where('fixup_problem', 'review:42')->count())->toBe(2);
});

it('asks for assistance once per set of pull request problems and withdraws it when the pull request is healthy', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Cancelled);
    tick_spent_fixup($group, 'check:Rust agent');
    tick_spent_fixup($group, 'check:Rust agent', TaskStatus::Failed);

    $conflict = ['merged' => false, 'state' => 'open', 'mergeable' => false, 'mergeable_state' => 'dirty', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']];
    $clean = ['merged' => false, 'state' => 'open', 'mergeable' => true, 'mergeable_state' => 'clean', 'head' => ['sha' => 'def456'], 'base' => ['ref' => 'main']];
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42/update-branch' => Http::response(['message' => 'merge conflict between base and head'], 422),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push($conflict)->push($conflict)
            ->push([...$clean, 'mergeable_state' => 'unstable'])
            ->push($clean),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => 0, 'check_suites' => []]),
        'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs*' => Http::response(['total_count' => 0, 'check_runs' => []]),
        'https://api.github.com/repos/acme/orbit/commits/def456/check-runs*' => Http::sequence()
            ->push(['total_count' => 1, 'check_runs' => [['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1']]])
            ->push(['total_count' => 1, 'check_runs' => [['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/2']]]),
    ]);
    $conflictReason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push. Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).';
    $checkReason = 'The pull request needs attention: Check Rust agent failed: https://github.com/acme/orbit/runs/1. Orbit reached the cap of 2 fixups for check:Rust agent in the current window (2 counted).';

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_question' => null, 'assistance_reason' => $conflictReason]);
    $requestedAt = $group->fresh()?->updated_at;
    $this->travel(1)->minute();

    app(TaskScheduler::class)->tick();
    expect(tick_assistance_reasons())->toBe([$conflictReason])
        ->and($group->fresh()?->updated_at?->equalTo($requestedAt))->toBeTrue();

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_question' => null, 'assistance_reason' => $checkReason]);
    expect(tick_assistance_reasons())->toBe([$conflictReason, $checkReason]);

    // A re-run on the same head commit is read once the minute-long check cache expires.
    $this->travel(61)->seconds();
    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => false, 'assistance_kind' => null, 'assistance_question' => null, 'assistance_reason' => null]);
    expect(tick_assistance_reasons())->toHaveCount(2)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(5);
});

it('leaves another cause of assistance on a settling group alone while its pull request conflicts or recovers', function (): void {
    $group = tick_settling_group();
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => false, 'base' => ['ref' => 'main']])
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => true, 'base' => ['ref' => 'main']]),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    expect(tick_assistance_reasons())->toBe(['Merged pull request cleanup failed: disk full']);
});

it('does not replace an open direction request when the pull request needs attention', function (): void {
    $group = tick_settling_group();
    $question = 'Which base should the conflict follow?';
    $reason = "The reviewer is blocked: The pull request conflicts.\n\nQuestion: {$question}";
    test_update_task($group, [
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => false, 'base' => ['ref' => 'main']])
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => true, 'base' => ['ref' => 'main']]),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(tick_assistance_reasons())->toBe([$reason]);
});

it('withdraws its pull request assistance request when the pull request merges', function (): void {
    $group = tick_settling_group();
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.']);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'assistance_requested' => false, 'assistance_reason' => null]);
    expect(tick_vm_destroyed())->toBe(['tvm-'.$group->id]);
});

it('keeps another assistance reason without asking when the pull request merges', function (): void {
    $group = tick_settling_group();
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => 'The operator asked to hold this group.']);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'assistance_requested' => false, 'assistance_reason' => 'The operator asked to hold this group.']);
    expect(tick_vm_destroyed())->toBe(['tvm-'.$group->id]);
});

it('backs off a merged pull request cleanup and retries it on a later tick', function (): void {
    $group = tick_settling_group();
    tick_vm_destroy_fails(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect(tick_vm_destroyed())->toHaveCount(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_reason)->toBe('Merged pull request cleanup failed: disk full');

    $this->travel(TaskScheduler::AbandonedWorkspaceBackoffSeconds + 1)->seconds();
    app(TaskScheduler::class)->tick();

    expect(tick_vm_destroyed())->toHaveCount(2)
        ->and($group->fresh()?->taskable_id)->toBe($group->taskable_id);
});

it('preserves labeled merge evidence and report metrics when cleanup retries lose GitHub evidence', function (): void {
    $group = tick_settling_group();
    $task = $group->tasks->sole();
    $changes = [$task->title];
    $decision = JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'task_group_id' => $group->id,
        'task_ids' => [$task->id],
        'approval_comment_id' => 901,
        'approval_changes' => $changes,
        'approval_changes_digest' => hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        'approval_changes_redacted' => false,
        'call_started_at' => now()->subMinute()->toIso8601String(),
        'questions' => ['subtask_'.$task->id => []],
        'input_state' => ['subtasks' => [['title' => $task->title]], 'pull_request' => ['changes' => $changes]],
        'answers' => ['subtask_'.$task->id => ['value' => true, 'selected_answer_probability' => 0.97]],
        'latency_ms' => 25,
    ]);
    tick_vm_destroy_fails(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push([
                'merged' => true, 'state' => 'closed', 'head' => ['sha' => 'head-sha'],
                'body' => "## Changes\n\n- {$task->title}\n", 'merge_commit_sha' => 'actual-merge-sha', 'merged_at' => '2026-10-01T10:00:00Z',
            ])
            ->push([
                'merged' => true, 'state' => 'closed', 'head' => ['sha' => 'head-sha'],
                'merge_commit_sha' => 'actual-merge-sha', 'merged_at' => '2026-10-01T10:00:00Z',
            ]),
    ]);

    app(TaskScheduler::class)->tick();
    $verified = $decision->fresh();
    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($verified?->labels['questions'] ?? [])->toBe([])
        ->and($verified?->labels['call']['label'])->toBe('correct');

    app(TaskScheduler::class)->tick();
    $retried = $decision->fresh();
    Artisan::call('orbit:tasks:jev-report', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['brief_coverage'];

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($retried?->merge_changes)->toBe($verified?->merge_changes)
        ->and($retried?->merge_changes_digest)->toBe($verified?->merge_changes_digest)
        ->and($retried?->merge_body_digest)->toBe($verified?->merge_body_digest)
        ->and($retried?->labels)->toBe($verified?->labels)
        ->and($report['calls'])->toBe(1)
        ->and($report['labeled_share'])->toBe(1)
        ->and($report['call_correct'])->toBe(1);
});

it('uses backoff for publication and removal retries and retries a failed manual complete', function (): void {
    [$group, $task, , , $publisher] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked the models.')]);
    $publisher->pushFailures = 1;
    $hold = 'The operator asked to hold this group.';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    test_update_task($task, ['assistance_requested' => true, 'assistance_reason' => $hold]);
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => $hold]);
    $this->travel(TaskScheduler::retryDelaySeconds(1))->seconds();
    app(TaskScheduler::class)->tick();

    expect($publisher->pushes)->toBe([$group->id, $group->id])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->assistance_reason)->toBe($hold)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe($hold);

    // Each ended group's workspace lives on its own task VM, so removal destroys that VM.
    $failing = [];
    tick_vm_provider()->onDestroy = static function (TaskVm $vm) use (&$failing): void {
        if (in_array($vm->name, $failing, true)) {
            throw new RuntimeException('disk full');
        }
    };
    $octet = 220;
    $ended = static function (string $title, TaskGroupStatus $status, array $attributes = []) use ($group, &$octet): Task {
        $octet++;
        $owner = test_create_task(Task::topLevel(), [
            'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'project_id' => $group->project_id,
            'title' => $title, 'brief' => $title, 'status' => $status, ...$attributes,
        ]);
        $node = Node::query()->create([
            'name' => 'tick-vm-'.$octet, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.'.$octet, 'wireguard_ip' => '10.44.0.'.$octet,
        ]);
        $workspace = Instance::query()->create([
            'project_id' => $group->project_id, 'node_id' => $node->id, 'name' => 'task-'.$owner->id, 'task_workspace_owner_id' => $owner->id,
            'checkout_path' => '/srv/orbit/apps/tick-app/workspace', 'branch' => 'task-'.$owner->id,
            'starting_commit' => str_repeat('a', 40), 'status' => 'source_resolved',
        ]);
        $owner->taskable()->associate($workspace);
        $owner->save();
        test_task_vm($owner, $node);

        return $owner->fresh(['project', 'tasks', 'taskable']) ?? $owner;
    };
    $attempts = static fn (Task $owner): int => count(array_filter(tick_vm_destroyed(), static fn (string $name): bool => $name === 'tvm-'.$owner->id));

    $cancelled = $ended('Ended', TaskGroupStatus::Cancelled);
    $workspace = $cancelled->taskable;
    $failing = ['tvm-'.$cancelled->id];

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
        ->and($attempts($cancelled))->toBe(1);
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    $this->travel(TaskScheduler::retryDelaySeconds(1) - 1)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($attempts($cancelled))->toBe(1);

    $this->travel(2)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($attempts($cancelled))->toBe(2);
    $this->travel(TaskScheduler::retryDelaySeconds(2) - 1)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($attempts($cancelled))->toBe(2);

    $this->travel(2)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($attempts($cancelled))->toBe(3);

    // The third failure waits five minutes, not the four minutes a doubled delay would use.
    $this->travel(239)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($attempts($cancelled))->toBe(3);
    $this->travel(62)->seconds();
    app(TaskScheduler::class)->removeAbandonedWorkspaces();
    expect($attempts($cancelled))->toBe(4);

    $settling = $ended('Manual complete', TaskGroupStatus::Settling, ['pr_url' => 'https://github.com/acme/orbit/pull/77']);
    $kept = $settling->taskable;
    $failing[] = 'tvm-'.$settling->id;

    $completed = app(CompleteTaskGroupAction::class)->execute($settling);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($kept->id)
        ->and($completed->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'disk full');

    $failing = ['tvm-'.$cancelled->id];

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
        ->and(Instance::query()->find($kept->id))->toBeNull()
        ->and($settling->fresh()?->taskable_id)->toBeNull()
        ->and($settling->fresh()?->assistance_requested)->toBeFalse()
        ->and(Instance::query()->find($workspace->id))->not->toBeNull();
});

it('replaces its pull request assistance request with the cleanup failure when a merged group cannot complete', function (): void {
    $group = tick_settling_group();
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.']);
    tick_vm_destroy_fails(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_kind' => 'removal_failure', 'assistance_question' => null, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
});

it('does not replace an open direction request when merged pull request cleanup fails', function (): void {
    $group = tick_settling_group();
    $question = 'Should the merged branch be kept?';
    $reason = "The reviewer is blocked: The merge removed a migration.\n\nQuestion: {$question}";
    test_update_task($group, [
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    tick_vm_destroy_fails(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('changes nothing on a settling group when GitHub cannot report the pull request', function (): void {
    $group = tick_settling_group();
    $waiting = tick_appended_subtask($group);
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.';
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => $reason]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([], 502),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => $reason]);
    $this->assertDatabaseHas('tasks', ['id' => $waiting->id, 'status' => 'todo']);
    expect(tick_assistance_reasons())->toBe([$reason]);
});

it('appends one conflict fixup and reuses its turn fetch before fast-forwarding and starting', function (): void {
    $group = tick_settling_group();
    Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Operator', 'brief' => 'Not a fixup.', 'status' => TaskStatus::Completed,
    ]);
    Task::query()->create([
        'parent_id' => $group->id, 'position' => 3, 'title' => 'Operator again', 'brief' => 'Still not a fixup.', 'status' => TaskStatus::Completed,
    ]);
    $agents = tick_running_agents();
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
    ], ['abc123' => [[
        'name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->title)->toBe('Merge origin/main')
        ->and($fixup->brief)->toBe('Merge origin/main into the task branch and resolve the conflicts. Do not rebase and do not force-push.')
        ->and($fixup->status)->toBe(TaskStatus::Running)
        ->and($fixup->deliverables)->toBe([[
            'id' => 'fixup-review', 'type' => 'review',
            'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
        ]])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->where('fixup_problem', 'check:Rust agent')->exists())->toBeFalse()
        ->and($agents->events)->toBe(['turn-fetch', 'fast-forward', 'spawn'])
        ->and($agents->turnFetches)->toBe(1)
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
});

it('appends one check fixup naming the failed check and its url', function (TaskGroupStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => $status]);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Custom', 'status' => 'completed', 'conclusion' => 'timed_out', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    expect($fixup->fixup_head_sha)->toBe('abc123')
        ->and($fixup->title)->toBe('Fix Custom')
        ->and($fixup->brief)->toBe('Check Custom failed: https://github.com/acme/orbit/runs/9. Do not rebase and do not force-push.'."\n\nOrbit could not read the log.")
        ->and($fixup->status)->toBe(TaskStatus::Running)
        ->and($fixup->deliverables)->toBe([[
            'id' => 'fixup-review', 'type' => 'review',
            'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
        ]])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
})->with([TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview]);

it('puts redacted CI job evidence in the selected fixup brief without storage authentication', function (): void {
    Exceptions::fake()->throwOnReport();
    $group = tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'id' => 9, 'name' => 'Remote programs', 'status' => 'completed', 'conclusion' => 'failure',
        'html_url' => 'https://github.com/acme/orbit/actions/runs/1/job/9', 'app' => ['slug' => 'github-actions'],
    ]]]);
    Http::fake([
        'https://api.github.com/repos/acme/orbit/actions/jobs/9/logs' => Http::response('', 302, ['Location' => 'https://logs.blob.core.windows.net/job?sig=storage-secret']),
        'https://logs.blob.core.windows.net/job?sig=storage-secret' => Http::response("2026-10-18T10:11:12.123Z \e[31m##[error]Remote program failed\e[0m\nTOKEN=ci-secret-value\n"),
    ]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Remote programs')->sole()->brief)
        ->toContain('This is the CI evidence', '##[error]Remote program failed', 'TOKEN=[REDACTED]')
        ->not->toContain('ci-secret-value')
        ->not->toContain('storage-secret')
        ->not->toContain('2026-10-18T')
        ->not->toContain("\e[")
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/actions/jobs/9/logs') && $request->hasHeader('Authorization', 'Bearer ghs_watch'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'logs.blob.core.windows.net') && ! $request->hasHeader('Authorization'));
});

it('keeps a check fixup running when GitHub refuses its log', function (): void {
    Exceptions::fake()->throwOnReport();
    $group = tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'id' => 9, 'name' => 'Remote programs', 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'github-actions'],
    ]]]);
    Http::fake(['https://api.github.com/repos/acme/orbit/actions/jobs/9/logs' => Http::response('private-log-detail', 403)]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Remote programs')->sole();
    expect($fixup->brief)->toBe("Check Remote programs failed. Do not rebase and do not force-push.\n\nOrbit could not read the log.")
        ->and($fixup->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('includes another Apps check summary without requesting an Actions log', function (): void {
    tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'id' => 9, 'name' => 'Security scan', 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'scanner'],
        'output' => ['title' => 'Dependency issue', 'summary' => 'Update the dependency. TOKEN=ci-secret-value'],
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Security scan')->sole()->brief)
        ->toContain('This is the CI evidence', 'Dependency issue', 'Update the dependency. TOKEN=[REDACTED]')
        ->not->toContain('ci-secret-value');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/actions/'));
});

it('does not read job logs when the check fixup cap is spent', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'check:Remote programs');
    tick_spent_fixup($group, 'check:Remote programs');
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'id' => 9, 'name' => 'Remote programs', 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'github-actions'],
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Remote programs')->count())->toBe(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/actions/'));
});

it('runs make check for a fixup on a non-Orbit Project and on orbit', function (string $slug): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => $slug, 'task_check' => 'make check']);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Rust agent')->sole();
    expect($fixup->brief)->toBe('Check Rust agent failed: https://github.com/acme/orbit/runs/9. Do not rebase and do not force-push.'."\n\nOrbit could not read the log.")
        ->and($fixup->deliverables)->toBe([[
            'id' => 'fixup-review', 'type' => 'review',
            'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
        ]])
        ->and($agents->spawned)->toBe([$fixup->id]);
})->with([
    'another project' => ['shop'],
    'orbit' => ['orbit'],
]);

it('asks the reviewer to confirm a conflict fixup when the Project has no check', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'shop', 'task_check' => null]);
    tick_running_agents();
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
    ], ['abc123' => [[
        'name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->deliverables)->toBe([[
        'id' => 'fixup-review', 'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]])
        ->and(Task::query()->where('fixup_problem', 'like', 'check:%')->exists())->toBeFalse();
});

it('asks the reviewer to confirm a check fixup when the Project has no check', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'orbit', 'task_check' => null]);
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Gateway')->sole()->deliverables)->toBe([[
        'id' => 'fixup-review', 'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]]);
});

it('keeps a fixup deliverable after the Project check changes', function (): void {
    $group = tick_settling_group();
    $group->project->update(['task_check' => 'make check']);
    tick_running_agents();
    $failed = ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'];
    tick_watch_pulls([
        tick_open_pull(),
        tick_open_pull(['head' => ['sha' => 'def456']]),
    ], [
        'abc123' => [$failed],
        'def456' => [$failed],
    ]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    $recorded = [[
        'id' => 'fixup-review', 'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]];
    expect($fixup->deliverables)->toBe($recorded);

    $fixup->update(['status' => TaskStatus::Completed]);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $fixup->id, 'type' => 'approved', 'body' => 'Approved.',
        'author' => 'reviewer', 'review_attempt' => 1, 'commit_sha' => str_repeat('d', 40), 'posted_at' => now(),
    ]);
    $group->refresh();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->project->update(['task_check' => 'npm test']);

    app(TaskScheduler::class)->tick();

    $next = Task::query()->where('fixup_problem', 'check:Custom')->orderByDesc('position')->first();
    expect($fixup->fresh()?->deliverables)->toBe($recorded)
        ->and($next?->id)->not->toBe($fixup->id)
        ->and($next?->fixup_head_sha)->toBe('def456')
        ->and($next?->deliverables)->toBe([[
            'id' => 'fixup-review', 'type' => 'review',
            'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
        ]]);
});

it('appends a check fixup without a url when the run has none', function (): void {
    $group = tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Deploy', 'status' => 'completed', 'conclusion' => 'action_required',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Deploy')->sole()->brief)
        ->toBe('Check Deploy failed. Do not rebase and do not force-push.'."\n\nOrbit could not read the log.");
});

it('appends a fresh conflict fixup after operator work', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main');
    tick_appended_subtask($group)->update(['status' => TaskStatus::Completed]);

    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect(tick_assistance_reasons())->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(3)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->orderByDesc('position')->first()?->status)->toBe(TaskStatus::Running)
        ->and($agents->turnFetches)->toBe(1);
});

it('asks for assistance instead of a third fixup for the same problem', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Cancelled);

    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push. Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(2);
});

it('reports only the active-window count after an operator reset', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main');
    tick_appended_subtask($group)->update(['status' => TaskStatus::Completed]);
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Failed);

    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push. Orbit reached the cap of 2 fixups for conflict:main in the current window (2 counted).';

    app(TaskScheduler::class)->tick();

    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->where('fixup_problem', 'conflict:main')->count())->toBe(4);
});

it('appends a conflict fixup when a different-cased problem is already at the cap', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:Main');
    tick_spent_fixup($group, 'conflict:Main');
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'conflict:main')->sole()->status)->toBe(TaskStatus::Running)
        ->and($agents->turnFetches)->toBe(1);
});

it('appends the next failed check in GitHub order after the conflict cap', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'orbit']);
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'conflict:main', TaskStatus::Failed);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/custom'],
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/gateway'],
    ]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    expect($fixup->deliverables)->toBe([[
        'id' => 'fixup-review', 'type' => 'review',
        'description' => 'Confirm the conflict or failed check is resolved from the available evidence.',
    ]])
        ->and(Task::query()->where('fixup_problem', 'check:Gateway')->exists())->toBeFalse()
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
});

it('creates no fixup for a green mergeable pull request', function (): void {
    $group = tick_settling_group();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/2',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->where('parent_id', $group->id)->where('status', 'todo')->exists())->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();
});

it('starts an appended subtask on an open settling pull request without adding a fixup', function (): void {
    $group = tick_settling_group();
    test_update_task($group, [
        'assistance_requested' => true,
        'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.',
    ]);
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($todo->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($todo->fresh()?->fixup_problem)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$todo->id]);
});

it('does not start an appended subtask while another assistance cause is set', function (): void {
    $group = tick_settling_group();
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe('Merged pull request cleanup failed: disk full')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([])
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();
});

it('does not replace an open direction request when the pull request closes', function (): void {
    $group = tick_settling_group();
    $question = 'Which database should this use?';
    $reason = "The implementer is blocked: Need a database.\n\nQuestion: {$question}";
    test_update_task($group, [
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    tick_appended_subtask($group);
    tick_running_agents();
    tick_watch_pulls([['merged' => false, 'state' => 'closed']]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('asks for assistance for a closed pull request and does not start an appended subtask', function (): void {
    $group = tick_settling_group();
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([['merged' => false, 'state' => 'closed']]);
    Http::fake(['https://api.github.com/repos/acme/orbit/pulls?*' => Http::response([
        ['number' => 42, 'html_url' => $group->pr_url, 'state' => 'closed', 'merged_at' => null],
    ])]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_reason)->toBe('Watched pull request ended: '.$group->pr_url.' is closed. Open subtasks: #'.$todo->id.' '.$todo->title.'.')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([]);
});

it('does not start an appended subtask when the pull request merges', function (): void {
    $group = tick_settling_group();
    $todo = tick_appended_subtask($group);
    $agents = tick_running_agents();
    tick_watch_pulls([['merged' => true, 'state' => 'closed']]);
    Http::fake(['https://api.github.com/repos/acme/orbit/pulls?*' => Http::response([
        ['number' => 42, 'html_url' => $group->pr_url, 'state' => 'closed', 'merged_at' => '2026-10-08T10:00:00Z'],
    ])]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe('Watched pull request ended: '.$group->pr_url.' is merged. Open subtasks: #'.$todo->id.' '.$todo->title.'.')
        ->and($todo->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($agents->spawned)->toBe([])
        ->and(tick_vm_destroyed())->toBe([]);
});

it('starts an interrupted check fixup on the next tick without appending another', function (TaskStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => TaskGroupStatus::Running]);
    $fixup = tick_spent_fixup($group, 'check:Gateway', $status);
    if ($status === TaskStatus::Running) {
        $fixup->update(['started_at' => now()]);
    }
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect($fixup->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and(Task::query()->where('fixup_problem', 'check:Gateway')->count())->toBe(1)
        ->and($agents->fetched)->toBe([])
        ->and($agents->spawned)->toBe([$fixup->id]);
})->with([TaskStatus::Todo, TaskStatus::Running]);

it('starts an interrupted operator subtask on the next tick without appending a fixup', function (TaskStatus $status): void {
    $group = tick_settling_group();
    $group->update(['status' => TaskGroupStatus::Running]);
    $todo = tick_appended_subtask($group);
    if ($status === TaskStatus::Running) {
        $todo->update(['status' => TaskStatus::Running, 'started_at' => now()]);
    }
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty'])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    expect($todo->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($todo->fresh()?->fixup_problem)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([$todo->id]);
})->with([TaskStatus::Todo, TaskStatus::Running]);

it('cancels a stale conflict fixup before its implementer starts and returns to settling', function (): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    tick_running_agents(fetchFails: true);
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false]),
        tick_open_pull(['mergeable' => true, 'mergeable_state' => 'clean']),
    ], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null,
        'completion_summary' => 'Cancelled because the pull request is mergeable again; no conflict fixup is needed.',
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'waiting_for_review', 'assistance_requested' => false]);
    expect($fixup->fresh()?->settled_at)->not->toBeNull();
    expect($agents->spawned)->toBe([]);
    expect(AgentThread::query()->where('task_id', $fixup->id)->exists())->toBeFalse();
});

it('cancels an unstarted conflict fixup when its pull request closes and lets settling ask for assistance', function (): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    tick_running_agents(fetchFails: true);
    $closed = tick_open_pull(['state' => 'closed', 'mergeable' => null]);
    tick_watch_pulls([tick_open_pull(['mergeable' => false]), $closed, $closed], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null,
        'completion_summary' => 'Cancelled because the pull request closed without merging; no conflict fixup can proceed.',
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'waiting_for_review']);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'waiting_for_review', 'assistance_requested' => true,
        'assistance_reason' => 'The expected pull request closed without merging.', 'taskable_id' => $group->taskable_id]);
    expect($agents->spawned)->toBe([]);
});

it('cancels an unstarted conflict fixup on merge and preserves orphaned approval safeguards', function (bool $orphaned): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    $commit = $orphaned ? str_repeat('d', 40) : 'abc123';
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks()->sole()->id,
        'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $commit,
    ]);
    tick_running_agents(fetchFails: true);
    $merged = tick_open_pull(['merged' => true, 'state' => 'closed', 'mergeable' => null]);
    tick_watch_pulls([tick_open_pull(['mergeable' => false]), $merged, $merged], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', [
        'id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null,
        'completion_summary' => 'Cancelled because the pull request merged; no conflict fixup is needed.',
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'waiting_for_review']);

    app(TaskScheduler::class)->tick();

    if ($orphaned) {
        $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'waiting_for_review', 'assistance_requested' => true, 'taskable_id' => $group->taskable_id]);
        expect($group->fresh()?->assistance_reason)->toStartWith(TaskScheduler::OrphanedCommitPrefix.'Commit '.$commit);
    } else {
        $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'completed', 'taskable_id' => null]);
    }
    expect($agents->spawned)->toBe([]);
    expect(tick_vm_destroyed())->toBe($orphaned ? [] : ['tvm-'.$group->id]);
})->with(['merged approval' => false, 'approval missed merge' => true]);

it('retains an orphaned approval after interruption between settling and recording its hold', function (bool $otherAssistance, bool $direction): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    $instanceId = $group->taskable_id;
    $commit = str_repeat('d', 40);
    TaskComment::query()->create([
        'task_group_id' => $group->id, 'task_id' => $group->tasks()->sole()->id,
        'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $commit,
    ]);
    mock(TaskSettleMetricsCollector::class)->shouldNotReceive('collect');

    tick_running_agents(fetchFails: true);
    $merged = tick_open_pull(['merged' => true, 'state' => 'closed', 'mergeable' => null]);
    tick_watch_pulls([tick_open_pull(['mergeable' => false]), $merged, $merged, $merged], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();
    // Abort the first hold write, after the preceding settling transaction has committed.
    DB::statement("CREATE TRIGGER interrupt_orphaned_approval_hold BEFORE UPDATE ON tasks WHEN NEW.id = {$group->id} AND NEW.assistance_reason LIKE 'An approved commit is not on the pull request:%' BEGIN SELECT RAISE(ABORT, 'Gateway interrupted before orphaned hold'); END");
    try {
        test_tick_reporting(QueryException::class, 'Gateway interrupted before orphaned hold');
    } finally {
        DB::statement('DROP TRIGGER interrupt_orphaned_approval_hold');
    }
    $this->assertDatabaseHas('tasks', ['id' => $fixup->id, 'status' => 'cancelled', 'implementer_agent_thread_id' => null]);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => false, 'taskable_id' => $instanceId]);
    if ($otherAssistance) {
        TaskAssistance::apply($group, $direction ? AssistanceKind::Direction : AssistanceKind::Failure, $direction ? 'Which branch should retain the approval?' : null, 'Another assistance cause.');
    }

    // A fresh Gateway tick must revalidate the approval, not trust the absent hold.
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'taskable_id' => $instanceId]);
    $this->assertDatabaseHas('instances', ['id' => $instanceId]);
    expect(tick_vm_destroyed())->toBe([]);
    if ($direction) {
        expect($group->fresh()?->assistance_reason)->toBe('Another assistance cause.')
            ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
            ->and($group->fresh()?->assistance_question)->toBe('Which branch should retain the approval?');
        expect(tick_assistance_reasons())->toBe(['Another assistance cause.']);
    } else {
        expect($group->fresh()?->assistance_reason)->toStartWith(TaskScheduler::OrphanedCommitPrefix.'Commit '.$commit);
        expect(tick_assistance_reasons())->toBe([...($otherAssistance ? ['Another assistance cause.'] : []), $group->fresh()?->assistance_reason]);
    }
    expect($agents->spawned)->toBe([]);
})->with([
    'hold missing after interruption' => [false, false],
    'another failure after interruption' => [true, false],
    'direction request after interruption' => [true, true],
]);

it('waits without cancelling or spawning a conflict fixup when mergeability is unknown', function (): void {
    $group = tick_settling_group();
    $group->update(['settled_at' => now()]);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->once()->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    tick_running_agents(fetchFails: true);
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false]),
        tick_open_pull(['mergeable' => null, 'mergeable_state' => 'unknown']),
        tick_open_pull(),
    ], ['abc123' => []]);
    app(TaskScheduler::class)->tick();
    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    $this->travel(60)->seconds();
    $agents = tick_running_agents();

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $fixup->id, 'status' => 'running', 'completion_summary' => null, 'implementer_agent_thread_id' => null]);
    expect($agents->spawned)->toBe([]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $fixup->id, 'status' => 'cancelled']);
    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'waiting_for_review']);
    expect($agents->spawned)->toBe([]);
});

it('leaves a conflict fixup todo when the base fetch fails', function (): void {
    $group = tick_settling_group();
    $agents = tick_running_agents(fetchFails: true);
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->status)->toBe(TaskStatus::Todo)
        ->and($fixup->communication_failures)->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($agents->events)->toBe(['turn-fetch'])
        ->and($agents->spawned)->toBe([]);
});

it('retries a failed conflict fixup fetch on the backoff and asks for assistance on the fifth failure', function (): void {
    $group = tick_settling_group();

    $agents = tick_running_agents(fetchFails: true);
    tick_watch_pulls([tick_open_pull(['mergeable' => false])], ['abc123' => []]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($agents->turnFetches)->toBe(1);

    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    $fixup = Task::query()->where('fixup_problem', 'conflict:main')->sole();
    expect($fixup->status)->toBe(TaskStatus::Todo)
        ->and($fixup->communication_failures)->toBe(5)
        ->and($fixup->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($fixup->assistance_question)->toBeNull()
        ->and($fixup->assistance_reason)->toBe('The base branch could not be fetched.')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_reason)->toBe('The base branch could not be fetched.')
        ->and(tick_assistance_reasons())->toBe(['The base branch could not be fetched.']);

    app(TaskScheduler::class)->tick();

    expect($fixup->fresh()?->communication_failures)->toBe(5)
        ->and($agents->turnFetches)->toBe(5);
});

it('appends no fixup while the head is the one the last fixup committed from', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'check:Custom', headSha: 'abc123', commit: str_repeat('d', 40));

    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [[
        'name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_assistance_reasons())->toBe([])
        ->and($agents->spawned)->toBe([]);
});

it('asks for assistance instead of a second fixup when the last fixup changed nothing', function (?string $commit, TaskStatus $status): void {
    $group = tick_settling_group();
    $spent = tick_spent_fixup($group, 'check:Custom', $status, headSha: 'abc123', commit: $commit);

    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(), tick_open_pull()], ['abc123' => [[
        'name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9',
    ]]]);
    $reason = 'The pull request needs attention: Check Custom failed: https://github.com/acme/orbit/runs/9. Fixup subtask #'.$spent->id.' changed nothing, so Orbit does not try again on the same result.';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(tick_assistance_reasons())->toBe([$reason])
        ->and($agents->spawned)->toBe([]);
})->with([
    'same commit' => ['abc123', TaskStatus::Completed],
    'no approval' => [null, TaskStatus::Cancelled],
]);

it('waits for the checks on a new head to complete before the next fixup', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'check:Custom', headSha: 'abc123', commit: str_repeat('d', 40));
    $agents = tick_running_agents();
    $failed = ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/10'];
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(tick_open_pull(['head' => ['sha' => 'def456']])),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => 0, 'check_suites' => []]),
        'https://api.github.com/repos/acme/orbit/commits/def456/check-runs*' => Http::sequence()
            ->push(['total_count' => 2, 'check_runs' => [$failed, ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/orbit/runs/11']]])
            ->push(['total_count' => 2, 'check_runs' => [$failed, ['name' => 'Web', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/11']]]),
    ]);

    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Custom failed: https://github.com/acme/orbit/runs/10.';
    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(1)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(tick_assistance_reasons())->toBe([$reason]);

    $this->travel(61)->seconds();
    app(TaskScheduler::class)->tick();

    $next = Task::query()->where('fixup_problem', 'check:Custom')->orderByDesc('position')->first();
    expect(Task::query()->where('fixup_problem', 'check:Custom')->count())->toBe(2)
        ->and($next?->fixup_head_sha)->toBe('def456')
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($agents->spawned)->toBe([$next?->id]);
});

it('appends one fixup for a failed check and ignores the Required checks rollup it explains', function (): void {
    $group = tick_settling_group();
    $group->project->update(['slug' => 'orbit']);
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->whereNotNull('fixup_problem')->pluck('fixup_problem')->all())->toBe(['check:Gateway']);
});

it('waits with backoff on cancelled checks and asks for assistance only when they persist', function (string $conclusion): void {
    $group = tick_settling_group();

    $agents = tick_running_agents();
    $pulls = array_fill(0, 12, tick_open_pull());
    tick_watch_pulls($pulls, ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => $conclusion, 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect(tick_assistance_reasons())->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();

    $this->travel(1800)->seconds();
    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Gateway failed: https://github.com/acme/orbit/runs/1. Those checks were cancelled or could not start, and did not recover. Re-run them.';
    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
})->with(['cancelled', 'startup_failure']);

it('fixes a genuine failure and does not count a cancelled check as a problem', function (): void {
    $group = tick_settling_group();
    tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'cancelled', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->whereNotNull('fixup_problem')->pluck('fixup_problem')->all())->toBe(['check:Custom']);
});

it('reports a genuine failure while another check is pending and appends no check fixup', function (): void {
    $group = tick_settling_group();

    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull(), tick_open_pull()], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(30)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11', 'id' => 11],
    ]]);
    $reason = 'The pull request needs attention: Check Custom failed: https://github.com/acme/orbit/runs/9.';

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
});

it('appends a conflict fixup while a check is pending', function (): void {
    $group = tick_settling_group();
    $agents = tick_running_agents();
    tick_watch_pulls([
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
        tick_open_pull(['mergeable' => false, 'mergeable_state' => 'dirty']),
    ], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(5)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11'],
    ]]);

    app(TaskScheduler::class)->tick();

    expect(Task::query()->where('fixup_problem', 'conflict:main')->sole()->status)->toBe(TaskStatus::Running)
        ->and(Task::query()->where('fixup_problem', 'like', 'check:%')->exists())->toBeFalse()
        ->and($agents->turnFetches)->toBe(1)
        ->and($agents->spawned)->not->toBe([]);
});

it('fixes a genuine failure beside a check pending for more than 60 minutes', function (): void {
    $group = tick_settling_group();
    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Custom', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/9'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(61)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11'],
    ]]);

    app(TaskScheduler::class)->tick();

    $fixup = Task::query()->where('fixup_problem', 'check:Custom')->sole();
    expect($fixup->status)->toBe(TaskStatus::Running)
        ->and(Task::query()->where('fixup_problem', 'check:Web')->exists())->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($agents->spawned)->toBe([$fixup->id]);
});

it('uses the infrastructure path when a check is pending for more than 60 minutes', function (): void {
    $group = tick_settling_group();

    $agents = tick_running_agents();
    tick_watch_pulls(array_fill(0, 12, tick_open_pull()), ['abc123' => [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null,
        'started_at' => now()->subMinutes(61)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11',
    ]]]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect(tick_assistance_reasons())->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();

    $this->travel(1800)->seconds();
    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Web is still pending: https://github.com/acme/orbit/runs/11. Those checks were cancelled or could not start, and did not recover. Re-run them.';
    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
});

it('ages a pending check with no started_at from the first read and does not move that time', function (): void {
    $group = tick_settling_group();

    tick_running_agents();
    tick_watch_pulls(array_fill(0, 12, tick_open_pull()), ['abc123' => [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/orbit/runs/11',
    ]]]);

    app(TaskScheduler::class)->tick();
    $this->travel(50)->minutes();
    app(TaskScheduler::class)->tick();
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();

    $this->travel(11)->minutes();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }
    expect(tick_assistance_reasons())->toBe([]);

    $this->travel(1800)->seconds();
    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Web is still pending: https://github.com/acme/orbit/runs/11. Those checks were cancelled or could not start, and did not recover. Re-run them.';
    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse();
});

it('does not start infrastructure backoff while a check is still pending', function (): void {
    $group = tick_settling_group();

    $agents = tick_running_agents();
    tick_watch_pulls(array_fill(0, 12, tick_open_pull()), ['abc123' => [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'cancelled', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'started_at' => now()->subMinutes(10)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11'],
    ]]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    foreach ([60, 120, 300, 600, 1800] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect(tick_assistance_reasons())->toBe([])
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and(Task::query()->whereNotNull('fixup_problem')->exists())->toBeFalse()
        ->and($agents->spawned)->toBe([]);
});

it('asks for assistance once the group has three Gateway fixups', function (): void {
    $group = tick_settling_group();
    tick_spent_fixup($group, 'conflict:main');
    tick_spent_fixup($group, 'check:Custom');
    tick_spent_fixup($group, 'check:Lint');

    $agents = tick_running_agents();
    tick_watch_pulls([tick_open_pull()], ['abc123' => [
        ['name' => 'Deploy', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/3'],
    ]]);

    app(TaskScheduler::class)->tick();

    $reason = 'The pull request needs attention: Check Deploy failed: https://github.com/acme/orbit/runs/3. Orbit already appended 3 fixups to this group.';
    expect(tick_assistance_reasons())->toBe([$reason])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and(Task::query()->whereNotNull('fixup_problem')->count())->toBe(3)
        ->and($agents->spawned)->toBe([]);
});

it('fast-forwards the workspace after the turn fetch and backs off until assistance on the fifth failure', function (): void {
    $group = tick_settling_group();
    $waiting = tick_appended_subtask($group);
    $agents = tick_running_agents(fastForwardFails: true);

    tick_watch_pulls(array_fill(0, 6, tick_open_pull()), ['abc123' => []]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($agents->fastForwards)->toBe(1)
        ->and($agents->turnFetches)->toBe(1)
        ->and($agents->events)->toBe(['turn-fetch', 'fast-forward'])
        ->and($agents->missingRefOk)->toBeFalse()
        ->and($waiting->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($waiting->fresh()?->communication_failures)->toBe(1)
        ->and($agents->spawned)->toBe([]);

    $this->travel(60)->seconds();
    app(TaskScheduler::class)->tick();

    expect($agents->fastForwards)->toBe(2)
        ->and($agents->turnFetches)->toBe(2)
        ->and($waiting->fresh()?->communication_failures)->toBe(2);

    foreach ([120, 300, 600] as $seconds) {
        $this->travel($seconds)->seconds();
        app(TaskScheduler::class)->tick();
    }

    expect($agents->fastForwards)->toBe(5)
        ->and($agents->turnFetches)->toBe(5)
        ->and($waiting->fresh()?->status)->toBe(TaskStatus::Todo)
        ->and($waiting->fresh()?->communication_failures)->toBe(5)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and(tick_assistance_reasons())->toBe(['The task branch could not be fetched.']);

    app(TaskScheduler::class)->tick();

    expect($agents->fastForwards)->toBe(5)
        ->and($agents->turnFetches)->toBe(5)
        ->and($agents->spawned)->toBe([]);
});

it('reports a throwing brief coverage labeler and continues the tick', function (): void {
    $group = tick_settling_group();
    Exceptions::fake();
    app()->instance(BriefCoverageLabeler::class, new class implements BriefCoverageLabeler
    {
        public function label(Task $group, TaskPullRequestHealth $health): void
        {
            throw new RuntimeException('labeling failed');
        }
    });
    tick_watch_pulls([['merged' => true, 'state' => 'closed', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']]]);

    expect(app(TaskScheduler::class)->tick())->toBe([]);

    Exceptions::assertReported(RuntimeException::class);
});

it('keeps a merged group settling when an approved commit missed the merge', function (): void {
    $group = tick_settling_group();
    $reason = TaskScheduler::OrphanedCommitPrefix.'Commit '.str_repeat('d', 40).' reached task-'.$group->id.' after the merge.';
    test_update_task($group, ['assistance_requested' => true, 'assistance_reason' => $reason]);
    tick_watch_pulls([['merged' => true, 'state' => 'closed', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']]]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('tasks', ['id' => $group->id, 'status' => 'settling', 'assistance_reason' => $reason]);
});

it('returns no decisions when the tasks extension is disabled', function (): void {
    tick_group();

    expect(app(TaskScheduler::class)->tick())->toBe([]);
});

it('drains a pending approval chosen by the faked Choice', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId !== 'implementer-thread') {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }

            return [
                'thread' => [
                    'session' => ['status' => 'waiting'],
                    'pendingApprovals' => [['requestId' => 'approval-tick']],
                ],
            ];
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('waiting for input')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('counts a communication failure when a drain dispatch fails', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            throw new AgentDriverException('Agent approval respond failed.');
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId !== 'implementer-thread') {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }

            return [
                'thread' => [
                    'session' => ['status' => 'waiting'],
                    'pendingApprovals' => [['requestId' => 'approval-tick']],
                ],
            ];
        }
    });

    app(TaskScheduler::class)->tick();

    expect($group->tasks()->first()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('advances the current subtask when Jev marks it done', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $spawner = new class implements AgentSpawner
    {
        public int $spawned = 0;

        public function reserveReviewer(Task $task): ?int
        {

            return null;
        }

        public function spawnReviewer(Task $task): ?int
        {
            $this->spawned++;

            return test_agent_thread($task->parent, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    app()->instance(AgentSpawner::class, $spawner);

    $started = app(TaskScheduler::class)->tick();
    $decisions = app(TaskScheduler::class)->tick();

    expect($started)->toBe([])
        ->and($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($group->fresh()?->tasks->first()?->status)->toBe(TaskStatus::Reviewing)
        ->and($spawner->spawned)->toBe(1);
});

it('hands off with the Project task check, and runs no command when the Project has none', function (?string $taskCheck): void {
    $group = tick_group();
    $group->project->update(['task_check' => $taskCheck]);
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    tick_workspace();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(TaskCheckRunner::class)->commands)->toBe([$taskCheck]);
})->with([
    'no task check' => [null],
    'custom task check' => ['vp run check'],
]);

it('skips assistance and communication failures when cancellation lands during observation', function (TaskStatus $status, AgentThreadState $state): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->update(['status' => $status, 'review_notified_attempt' => $task->review_attempt]);
    app(TaskExtensionState::class)->enable();
    $driver = new FakeAgentDriver('example');
    $driver->observation = new AgentObservation($state, turnId: 'cancel-race-turn');
    AgentThread::query()->where('task_group_id', $group->id)->update(['driver' => 'example']);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    mock(TaskWorkspaceDiffReader::class)->shouldReceive('hasCommitsSince')->once()
        ->andReturnUsing(function () use ($group): bool {
            // Commit the cancellation while observe() is in flight, leaving its loaded models stale.
            Task::topLevel()->whereKey($group->id)->update(['status' => TaskGroupStatus::Cancelled]);
            test_update_task($group->tasks(), ['status' => TaskStatus::Cancelled, 'settled_at' => now(), 'assistance_requested' => false]);

            return false;
        });
    mock(TaskTurnReceipts::class)->shouldReceive('read')
        ->andThrow(new TaskTurnReceiptException('The cancelled workspace is no longer available.'));

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Cancelled)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->assistance_reason)->toBeNull()
        ->and($task->fresh()?->communication_failures)->toBe(0);
})->with([
    'failed implementer' => [TaskStatus::Running, AgentThreadState::Failed],
    'implementer receipt read failure' => [TaskStatus::Running, AgentThreadState::Done],
    'failed reviewer' => [TaskStatus::Reviewing, AgentThreadState::Failed],
    'reviewer receipt read failure' => [TaskStatus::Reviewing, AgentThreadState::Done],
]);

it('records an unreachable workspace as a communication failure without aborting the tick', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $receipts = mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('read')->andThrow(new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.'));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
    Classification::assertNothingClassified();
});

it('records a turn receipt read failure without skipping the other group', function (): void {
    $running = tick_group();
    $implementer = $running->tasks->sole();
    $project = Project::query()->create([
        'name' => 'tick-review-legacy', 'slug' => 'tick-review-legacy',
        'repository_url' => 'git@example.test:tick-review-legacy.git', 'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'tick-review-legacy-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.213', 'wireguard_ip' => '10.44.0.213',
    ]);
    $reviewing = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Tick review', 'brief' => 'Review the records.', 'status' => TaskGroupStatus::Reviewing,
    ]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $reviewing->id,
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-22',
        'checkout_path' => '/srv/orbit/apps/tick-review-legacy/task-22', 'branch' => 'task-22', 'status' => 'source_resolved',
    ]);
    $reviewing->taskable()->associate($instance);
    $reviewing->save();
    $reviewer = Task::query()->create([
        'parent_id' => $reviewing->id, 'position' => 1, 'title' => 'Review', 'brief' => 'Review the records.',
        'status' => TaskStatus::Reviewing, 'started_at' => now(),
        'review_notified_attempt' => 1, 'review_notified_turn_id' => 'handoff-turn',
    ]);
    test_link_agent_threads($reviewing);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'done'],
                'latestTurn' => ['id' => 'review-turn', 'state' => 'completed'],
            ]];
        }
    });
    $receipts = mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('read')->andThrow(new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.'));

    app(TaskScheduler::class)->tick();

    expect($implementer->fresh()?->communication_failures)->toBe(1)
        ->and($implementer->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($reviewer->fresh()?->communication_failures)->toBe(1)
        ->and($reviewer->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($running->id)->toBeLessThan($reviewing->id);
});

it('stores a ready_for_review receipt, removes it, and hands off to the reviewer', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $contents = FakeTaskTurnReceipts::contents('ready_for_review', 'Added the models.');
    $receipts = new FakeTaskTurnReceipts([$contents]);
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $comment = $task->comments()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($comment->getRawOriginal('type'))->toBe('ready_for_review')
        ->and($comment->body)->toBe('Added the models.')
        ->and($comment->author)->toBe('implementer')
        ->and($comment->agent_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($comment->completion_attempt)->toBe($task->fresh()?->completion_attempt)
        ->and($comment->receipt_hash)->toBe(hash('sha256', $contents))
        ->and($receipts->cleared)->toBe([hash('sha256', $contents)]);
    Classification::assertNothingClassified();
});

it('stores a receipt that was read again after a crash only once', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $contents = FakeTaskTurnReceipts::contents('ready_for_review');
    $task->comments()->create([
        'task_group_id' => $group->id, 'type' => 'ready_for_review', 'body' => 'Done.', 'author' => 'implementer',
        'completion_attempt' => $task->completion_attempt, 'receipt_hash' => hash('sha256', $contents), 'posted_at' => now(),
    ]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([$contents]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('starts a consult when the implementer blocks before any reviewer exists', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('blocked', 'Installing the extension needs sudo, and sudo was denied.', 'May the Node user run sudo apt-get install php8.5-intl?')]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done']);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->sole();
    $opening = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === $reviewer->external_id);
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->consult_comment_id)->toBe($task->comments()->sole()->id)
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('blocked')
        ->and($receipts->cleared)->toHaveCount(1)
        ->and($receipts->modes)->toContain('consult')
        ->and($question->asked_by)->toBe(QuestionAsker::Implementer)
        ->and($question->status)->toBe(QuestionStatus::Open)
        ->and($question->consult)->toBeTrue()
        ->and($question->cause)->toBeNull()
        ->and($question->question)->toBe('May the Node user run sudo apt-get install php8.5-intl?')
        ->and($question->escalated_at)->toBeNull()
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(0)
        ->and($group->fresh()?->questions)->toBe(1)
        ->and($group->fresh()?->escalations)->toBe(0)
        ->and($opening['message']['text'] ?? null)->toContain('May the Node user run sudo apt-get install php8.5-intl?')
        ->and($opening['message']['text'] ?? null)->toContain('--outcome=answered');
});

it('waits for an earlier handoff check before dispatching a blocked implementer consult', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $handoff = $task->comments()->create([
        'task_group_id' => $group->id, 'type' => 'ready_for_review', 'body' => 'Earlier handoff.',
        'author' => 'implementer', 'completion_attempt' => $task->completion_attempt, 'posted_at' => now(),
    ]);
    $checks = new FakeTaskCheckRunner([
        TaskCheckReading::running(), TaskCheckReading::running(), FakeTaskCheckRunner::passed(),
    ]);
    app()->instance(TaskCheckRunner::class, $checks);
    $check = $task->checks()->create([
        'task_comment_id' => $handoff->id, 'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Running, 'pid' => 1234, 'process_started' => 'check-start',
        'head_before' => $checks->head, 'tree_before' => $checks->tree, 'started_at' => now(),
    ]);
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'A new implementer turn needs direction.', 'Which contract should I use?'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'done'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    $waiter = TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->sole();
    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Running)
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->comments()->where('type', 'blocked')->count())->toBe(0)
        ->and(TaskQuestion::query()->count())->toBe(0)
        ->and(AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->count())->toBe(0)
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->kind)->toBe('check')
        ->and($waiter->kind)->toBe('verdict')
        ->and($receipts->reads())->toBe(0)
        ->and($receipts->cleared)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($receipts->prepared)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Running)
        ->and($task->fresh()?->direction_answer_key)->toBeNull()
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'queued')->sole()->id)->toBe($waiter->id)
        ->and($receipts->reads())->toBe(0)
        ->and($receipts->cleared)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($receipts->prepared)->toBe([]);

    $state->reviewer = 'running';
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $starts = tick_reviewer_starts($dispatcher);
    $blocked = $task->comments()->where('type', 'blocked')->sole();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Passed)
        ->and($starts)->toHaveCount(1)
        ->and($waiter->fresh()?->status)->toBe('released')
        ->and($starts[0]['commandId'])->toBe(TaskWorkspaceLeaseRecord::query()->where('thread_id', $reviewer->id)->where('adopted', false)->sole()->dispatch_key)
        ->and($starts[0]['message']['text'])->toContain('Which contract should I use?')
        ->and($receipts->modes)->toBe(['consult', 'consult'])
        ->and($receipts->reads())->toBe(1)
        ->and($receipts->cleared)->toHaveCount(1)
        ->and($task->fresh()?->direction_answer_key)->toBeNull()
        ->and($task->fresh()?->consult_comment_id)->toBe($blocked->id)
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(TaskQuestion::query()->count())->toBe(1);
});

it('does not send a second consult when an accepted send lost its response', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(true);
    $attempt = $task->completion_attempt;
    $answer = 'Yes. The contract allows the intl extension.';

    app(TaskScheduler::class)->tick();

    $starts = tick_reviewer_starts($dispatcher);
    expect($starts)->toHaveCount(1)
        ->and($task->fresh()?->consult_comment_id)->not->toBeNull()
        ->and($task->fresh()?->direction_answer_key)->toBe($starts[0]['commandId'])
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(2);

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(2)
        ->and($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answer)->toBe($answer)
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($question->consult)->toBeTrue()
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and(TaskQuestion::query()->count())->toBe(1);

    app(TaskScheduler::class)->tick();

    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer)
        ->and(TaskQuestion::query()->count())->toBe(1);
});

it('keeps the consult answer when the marker update fails after the send', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(false);
    $attempt = $task->completion_attempt;
    $answer = 'Yes. The contract allows the intl extension.';
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject): void {
        if (! $inject || ! str_starts_with(strtolower(ltrim($sql)), 'update') || ! str_contains($sql, 'reviewer_agent_thread_id')) {
            return;
        }
        $inject = false;

        throw new RuntimeException('injected consult marker failure');
    });

    test_tick_reporting(RuntimeException::class, 'injected consult marker failure');

    $starts = tick_reviewer_starts($dispatcher);
    expect($starts)->toHaveCount(1)
        ->and($task->fresh()?->consult_comment_id)->not->toBeNull()
        ->and($task->fresh()?->direction_answer_key)->toBe($starts[0]['commandId'])
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and(TaskQuestion::query()->sole()->question)->toBe('May I install php8.5-intl?');

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(2)
        ->and($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answer)->toBe($answer)
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->consult_comment_id)->toBeNull();

    app(TaskScheduler::class)->tick();

    expect(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->id)->toBe($question->id)
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer);
});

it('reuses the fresh reviewer when saving its conversation id fails after the opening turn', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(false);
    $answer = 'Yes. The contract allows the intl extension.';
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject, $dispatcher): void {
        if (! $inject || tick_reviewer_starts($dispatcher) === []) {
            return;
        }
        $statement = strtolower(ltrim($sql));
        if (str_starts_with($statement, 'update') && str_contains($sql, 'external_id')) {
            $inject = false;

            throw new RuntimeException('injected external id failure');
        }
    });

    app(TaskScheduler::class)->tick();

    expect($inject)->toBeFalse();

    $created = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'create');
    $externalId = is_array($created) ? (string) ($created['threadId'] ?? '') : '';
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    expect($externalId)->not->toBe('')
        ->and($reviewer->external_id)->toBe($externalId)
        ->and($reviewer->external_id)->not->toStartWith('pending:')
        ->and(collect($dispatcher->commands)->where('type', 'create'))->toHaveCount(1)
        ->and(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);

    app(TaskScheduler::class)->tick();

    expect(collect($dispatcher->commands)->where('type', 'create'))->toHaveCount(1)
        ->and(tick_reviewer_starts($dispatcher))->toHaveCount(1)
        ->and($reviewer->fresh()?->external_id)->toBe($externalId)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer)
        ->and($task->fresh()?->completion_attempt)->toBe($task->completion_attempt);
});

it('reuses the Pi reviewer when both opening send responses are lost', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['reviewer_agent_driver' => 'pi', 'reviewer_model' => 'gpt-5.6-luna']);
    $group->taskable->node->update([
        'settings' => ['pi' => ['token' => 'pi-node-token-with-more-than-32-characters', 'url' => 'http://10.44.0.212:3774']],
    ]);
    $answer = 'Yes. The contract allows the intl extension.';
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', $answer, cause: 'missed_contract'),
        null,
    ]);
    $pi = (object) ['sessions' => [], 'keys' => [], 'implementerKey' => ''];
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([app(PiDriver::class)]));
    Http::fake(function (Request $request) use ($pi): mixed {
        if ($request->method() === 'POST' && str_ends_with(rtrim($request->url(), '/'), '/sessions')) {
            $pi->sessions[] = (string) $request['id'];

            return Http::response(['id' => $request['id']], 201);
        }
        if ($request->method() === 'POST' && str_contains($request->url(), '/messages')) {
            if (str_contains($request->url(), '/implementer-thread/')) {
                $pi->implementerKey = (string) $request['key'];

                return Http::response(['key' => $request['key'], 'status' => 'accepted'], 202);
            }
            $pi->keys[] = (string) $request['key'];

            throw new ConnectionException('response lost');
        }
        if ($request->method() === 'GET') {
            $sessionId = basename(rtrim($request->url(), '/'));

            return Http::response([
                'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 2,
                'session' => ['id' => $sessionId],
                'state' => 'done',
                'turnId' => $sessionId === 'implementer-thread' ? $pi->implementerKey : ($pi->keys[0] ?? ''),
                'entries' => [],
            ]);
        }

        return Http::response([], 404);
    });

    app(TaskScheduler::class)->tick();

    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    expect($pi->sessions)->toHaveCount(1)
        ->and($reviewer->external_id)->toBe($pi->sessions[0])
        ->and($reviewer->driver)->toBe('pi')
        ->and(array_unique($pi->keys))->toHaveCount(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);

    app(TaskScheduler::class)->tick();

    expect($pi->sessions)->toHaveCount(1)
        ->and(array_unique($pi->keys))->toHaveCount(1)
        ->and($reviewer->fresh()?->external_id)->toBe($pi->sessions[0])
        ->and(TaskQuestion::query()->sole()->answer)->toBe($answer)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->consult_comment_id)->toBeNull();
});

it('reminds a consult reviewer that stops without a receipt, then asks for a failure', function (): void {
    [$group, $task, $receipts, $dispatcher] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        null,
        null,
    ]);

    app(TaskScheduler::class)->tick();
    $reviewerId = $group->fresh()?->reviewer_agent_thread_id;

    app(TaskScheduler::class)->tick();

    $reminder = tick_reviewer_starts($dispatcher)[1]['message']['text'] ?? null;
    expect($reminder)->toBe(TaskTurnFetchNotice::Failed."\n\nOrbit could not confirm the review is complete. No turn receipt was found. ".TaskTurnInstructions::consult(is_int($reviewerId) ? $reviewerId : null))
        ->and($reminder)->not->toContain('--outcome=approved')
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(collect($receipts->modes)->filter(fn (?string $mode): bool => $mode === 'consult'))->toHaveCount(3);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.');
});

it('asks for a failure when the consult reviewer thread fails', function (): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
    ]);

    app(TaskScheduler::class)->tick();
    $state->reviewer = 'error';
    $state->reviewerError = 'The model rejected the turn.';

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_reason)->toBe('The reviewer thread failed.')
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);
});

it('resumes a consult reviewer after a server restart', function (): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
    ]);

    app(TaskScheduler::class)->tick();
    $state->reviewer = 'error';
    $state->reviewerError = TaskScheduler::PiServerRestartError;

    app(TaskScheduler::class)->tick();

    $resume = tick_reviewer_starts($dispatcher)[1] ?? null;
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('pending')
        ->and($resume['message']['text'] ?? null)->toBe(TaskTurnFetchNotice::Failed."\n\n".TaskScheduler::PiServerRestartContinue)
        ->and($resume['commandId'] ?? null)->toBe($task->fresh()?->pi_restart_key)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);
});

it('uses group-only observation assistance for a consult outage and clears it on recovery', function (): void {
    [$group, $task, $receipts, $dispatcher, $state] = tick_consult_exchange(false, [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
    ]);

    app(TaskScheduler::class)->tick();
    $state->unavailable = true;

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->agent_unavailable_since)->not->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    $this->travel(121)->seconds();
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Observation)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open);

    $state->unavailable = false;
    $state->reviewer = 'running';
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->agent_unavailable_since)->toBeNull()
        ->and($group->fresh()?->agent_unavailable_notified_at)->toBeNull();
    expect(Activity::query()->where('subject_id', $group->id)->whereIn('description', ['assistance requested', 'assistance cleared'])->pluck('description')->all())
        ->toBe(['assistance requested', 'assistance cleared']);
});

it('answers a consult in the same attempt and records the reviewer cause', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', 'Yes. The contract allows the intl extension.', cause: 'missed_contract'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'done', 'reviewer' => 'running'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();
    $reviewerId = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole()->id;

    app(TaskScheduler::class)->tick();

    expect($receipts->reads())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Open)
        ->and($task->fresh()?->consult_comment_id)->not->toBeNull();

    $state->reviewer = 'done';
    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    $delivered = collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread');
    expect($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answered_by)->toBe(QuestionAsker::Reviewer)
        ->and($question->answer)->toBe('Yes. The contract allows the intl extension.')
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($question->consult)->toBeTrue()
        ->and($question->escalated_at)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(0)
        ->and(AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole()->id)->toBe($reviewerId)
        ->and($delivered)->toHaveCount(1)
        ->and($delivered->first()['message']['text'] ?? null)->toContain('Yes. The contract allows the intl extension.');
});

it('escalates the same consult when the reviewer blocks', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The brief does not name a database.', 'Which database should this subtask use?'),
        FakeTaskTurnReceipts::contents('blocked', 'The contract does not choose a database.', 'Which database should the operator choose?', cause: 'contract_gap'),
    ]);
    tick_relay_runtime($receipts, tick_dispatcher(), (object) ['implementer' => 'done']);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect($question->status)->toBe(QuestionStatus::Escalated)
        ->and($question->asked_by)->toBe(QuestionAsker::Implementer)
        ->and($question->consult)->toBeTrue()
        ->and($question->question)->toBe('Which database should the operator choose?')
        ->and($question->cause)->toBe(QuestionCause::ContractGap)
        ->and($question->escalated_at)->not->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe('Which database should the operator choose?')
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->consult_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(1)
        ->and(TaskQuestion::query()->where('consult', true)->count())->toBe(1);
});

it('asks for direction on the third block and a later relay does not count toward that limit', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'First block.', 'Which mirror?'),
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'missed_contract'),
        FakeTaskTurnReceipts::contents('blocked', 'Second block.', 'Which region?'),
        FakeTaskTurnReceipts::contents('answered', 'Use the region in the brief.', cause: 'brief_unclear'),
        FakeTaskTurnReceipts::contents('blocked', 'Third block.', 'May I provision a database?'),
        FakeTaskTurnReceipts::contents('answered', 'Provision the shared database.', cause: 'environment'),
        FakeTaskTurnReceipts::contents('blocked', 'Fourth block.', 'May I buy more disk?'),
    ]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done']);
    $attempt = $task->completion_attempt;

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $third = TaskQuestion::query()->where('consult', false)->sole();
    expect(TaskQuestion::query()->where('consult', true)->count())->toBe(2)
        ->and($third->status)->toBe(QuestionStatus::Escalated)
        ->and($third->asked_by)->toBe(QuestionAsker::Implementer)
        ->and($third->question)->toBe('May I provision a database?')
        ->and($third->cause)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe('May I provision a database?')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the public mirror.')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the region in the brief.')
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->consult_comment_id)->toBeNull();

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Provision the shared database.', 'author' => 'operator',
    ]);
    expect($task->fresh()?->direction_relay_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($receipts->modes)->toContain('relay');

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->where('consult', true)->count())->toBe(2)
        ->and($third->fresh()?->status)->toBe(QuestionStatus::Answered)
        ->and($third->fresh()?->answered_by)->toBe(QuestionAsker::Operator)
        ->and($third->fresh()?->answer)->toBe('Provision the shared database.')
        ->and($third->fresh()?->cause)->toBe(QuestionCause::Environment)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->where('consult', true)->count())->toBe(2)
        ->and(TaskQuestion::query()->where('consult', false)->count())->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_question)->toBe('May I buy more disk?')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the public mirror.')
        ->and($task->fresh()?->assistance_reason)->toContain('Use the region in the brief.')
        ->and($task->fresh()?->completion_attempt)->toBe($attempt)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread')->contains(
            fn (array $command): bool => str_contains((string) ($command['message']['text'] ?? ''), 'Provision the shared database.'),
        ))->toBeTrue();
});

it('continues the consult reviewer when the subtask reaches review', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', 'Yes. The contract allows the intl extension.', cause: 'missed_contract'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Installed intl.'),
    ]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'done']);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reviewers = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->get();
    $reviewSend = collect($dispatcher->commands)->last(fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === $reviewer->external_id);
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($reviewers)->toHaveCount(1)
        ->and($reviewers->sole()->id)->toBe($reviewer->id)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
        ->and($reviewSend['message']['text'] ?? null)->toContain('Review subtask #'.$task->id);
});

it('flags the parent when a subtask already asks and the parent does not', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $question = 'Which database should this subtask use?';
    $reason = "The implementer is blocked: The mirror is down.\n\nQuestion: {$question}";
    test_update_task($task, [
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});
