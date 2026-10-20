<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\WatchIncomingPullRequestsAction;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskBranchUpdate;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskMergeStatus;
use App\Domain\Tasks\TaskPublication;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskReviewObservation;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Domain\Tasks\TaskReviewSelection;
use App\Domain\Tasks\TaskReviewTrust;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskType;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\TaskVms\ParkTaskVm;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskPullRequestReviewWatcher;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\RecordingTaskVmProvider;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    test_bind_snapshot_driver();
    app(TaskExtensionState::class)->enable();
});

const RM_HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const RM_COMMIT = 'cccccccccccccccccccccccccccccccccccccccc';
const RM_BASE = 'dddddddddddddddddddddddddddddddddddddddd';
const RM_PR_HEAD = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const RM_PR_URL = 'https://github.com/acme/orbit/pull/42';

function rm_project(bool $flow = true): Project
{
    return Project::query()->create([
        'name' => 'acme', 'slug' => 'acme-'.Str::random(6), 'repository_url' => 'https://github.com/acme/orbit.git',
        'default_branch' => 'main', 'task_check' => 'composer check',
        'review_and_merge' => $flow, 'merge_check' => $flow ? 'Required checks' : null,
    ]);
}

/**
 * A running review-and-merge group with its workspace. Each subtask is [title, status, type].
 *
 * @param  list<array{0: string, 1: TaskStatus, 2?: TaskType}>  $subtasks
 */
function rm_group(array $subtasks, TaskGroupStatus $status = TaskGroupStatus::Reviewing, bool $flow = true, ?string $prUrl = null, ?string $prBranch = null): Task
{
    $project = rm_project($flow);
    $node = Node::query()->create([
        'name' => 'rm-node-'.Str::random(4), 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.90', 'wireguard_ip' => '10.44.0.90',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Review and merge', 'brief' => 'Ship the feature.',
        'status' => $status, 'pr_url' => $prUrl, 'pr_branch' => $prBranch,
    ]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $group->id,
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/srv/orbit/apps/acme/task-'.$group->id, 'branch' => 'task-'.$group->id, 'status' => 'source_resolved',
        // The implementer admission gate validates deliverables against a resolved review base.
        'starting_commit' => str_repeat('a', 40),
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    foreach ($subtasks as $index => $subtask) {
        Task::query()->create([
            'parent_id' => $group->id, 'position' => $index + 1, 'title' => $subtask[0], 'brief' => 'Do '.$subtask[0].'.',
            'status' => $subtask[1], 'type' => $subtask[2] ?? TaskType::Implementation,
            'deliverables' => ($subtask[2] ?? null) === TaskType::FinalReview ? TaskFinalReview::deliverables()
                : [['id' => 'tests', 'type' => 'review', 'description' => 'Tests cover it.']],
            'started_at' => $subtask[1] === TaskStatus::Todo ? null : now(),
        ]);
    }

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/** Marks a subtask as reviewing with a requested review and a reviewer thread of its own. */
function rm_reviewing(Task $task, ?string $head = null, ?string $prHead = null): AgentThread
{
    $reviewer = test_agent_thread($task->parent, 'reviewer-'.$task->id);
    $reviewer->update(['task_id' => $task->id, 'role' => 'reviewer']);
    $task->parent->update(['reviewer_agent_thread_id' => $reviewer->id]);
    $task->update([
        'status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn',
        'review_workspace_head' => $head ?? RM_HEAD, 'review_workspace_tree' => str_repeat('b', 40), 'fixup_head_sha' => $prHead,
    ]);
    $checks = app(TaskCheckRunner::class);
    if ($checks instanceof FakeTaskCheckRunner) {
        $checks->head = $head ?? RM_HEAD;
    }

    return $reviewer;
}

/** @param  list<string|null>  $receipts */
function rm_runtime(array $receipts = []): object
{
    $runtime = new class implements AgentSpawner, TaskBaseBranchFetcher, TaskBriefCoverage, TaskPullRequestMerger, TaskPullRequestPublisher, TaskPullRequestUpdater, TaskWorkspaceSigner
    {
        /** @var list<int> */
        public array $reviewers = [];

        /** @var array<string, string> */
        public array $observedTurns = [];

        /** @var list<string> */
        public array $reviewPackets = [];

        /** @var list<int> */
        public array $implementers = [];

        /** @var list<string> */
        public array $pushes = [];

        /** @var list<string> */
        public array $branches = [];

        /** @var list<string> */
        public array $bodies = [];

        /** @var list<string> */
        public array $moves = [];

        /** @var list<array{string, string}> */
        public array $reviews = [];

        /** @var list<string> */
        public array $merges = [];

        /** @var list<string> */
        public array $updates = [];

        public RequiredCheckState $check = RequiredCheckState::Passed;

        public bool $mergeAccepted = true;

        public int $pushFailures = 0;

        /** @var list<GitHubListedPullRequest> */
        public array $open = [];

        public function reserveReviewer(Task $task): ?int
        {

            return null;

        }

        public function spawnReviewer(Task $task): ?int
        {
            $this->reviewers[] = $task->id;
            $this->reviewPackets[] = app(TaskReviewPacketBuilder::class)->build($task, false);
            $thread = test_agent_thread($task->parent, 'reviewer-'.$task->id);
            $thread->update(['task_id' => $task->id, 'role' => 'reviewer']);
            app(TaskWorkspaceLease::class)->turn($task->parent, TaskThreadRole::Reviewer, 'thread:'.$thread->id, $thread->id);
            $this->observedTurns[$thread->external_id] = app(TaskWorkspaceLease::class)->dispatch($thread);

            return $thread->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers[] = $task->id;

            $thread = AgentThread::query()->find($task->implementer_agent_thread_id)
                ?? test_agent_thread($task->parent, 'implementer-'.$task->id, $task);
            app(TaskWorkspaceLease::class)->turn($task->parent, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
            $this->observedTurns[$thread->external_id] = app(TaskWorkspaceLease::class)->dispatch($thread);

            return $thread->id;
        }

        public function requestReview(Task $task): void {}

        public function fetch(Task $group, string $base): void {}

        public function fastForward(Task $group, bool $missingRefOk = false): void {}

        public function resetToDefault(Task $group): string
        {
            return RM_BASE;
        }

        public function mergeBase(Task $group): string
        {
            return RM_BASE;
        }

        public function moveTo(Task $group, string $sha): void
        {
            $this->moves[] = $sha;
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = $sha;
            }
        }

        public int $turnFetches = 0;

        public function fetchForTurn(Task $group): void
        {
            $this->turnFetches++;
        }

        public function publish(Task $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;

            return RM_PR_URL;
        }

        public function push(Task $group, string $commit): void
        {
            if ($this->pushFailures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
            $this->pushes[] = $commit;
            $this->branches[] = TaskRemoteBranch::for($group);
        }

        public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
        {
            return $this->check;
        }

        public function merge(Task $group, string $sha): GitHubMergeResult
        {
            $this->merges[] = $sha;

            return $this->mergeAccepted
                ? new GitHubMergeResult(true, str_repeat('f', 40), 200, 'Pull Request successfully merged')
                : new GitHubMergeResult(false, null, 405, 'Required status check "Required checks" is expected');
        }

        public function review(Task $group, string $sha, GitHubReviewEvent $event, string $body): int
        {
            $this->reviews[] = [$sha, $event->value];

            return 7000 + count($this->reviews);
        }

        public function openPullRequests(Project $project): array
        {
            return $this->open;
        }

        public function greenDefaultTipAfter(Task $group, string $sha, string $checkName): ?string
        {
            return null;
        }

        public function commit(Instance $instance, string $message): ?string
        {
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = RM_COMMIT;
            }

            return RM_COMMIT;
        }

        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            return [];
        }

        public function updateBranch(Task $group, string $headSha): TaskBranchUpdate
        {
            $this->updates[] = $headSha;

            return TaskBranchUpdate::Accepted;
        }
    };
    foreach ([AgentSpawner::class, TaskBaseBranchFetcher::class, TaskPullRequestPublisher::class, TaskPullRequestMerger::class, TaskWorkspaceSigner::class, TaskBriefCoverage::class, TaskPullRequestUpdater::class] as $abstract) {
        app()->instance($abstract, $runtime);
    }
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts($receipts));
    app()->instance(AgentCommandDispatcher::class, new class implements AgentCommandDispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    });
    app()->instance(AgentSnapshotReader::class, new class($runtime) implements AgentSnapshotReader
    {
        public function __construct(private object $runtime) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => $this->runtime->observedTurns[$threadId] ?? 'turn-of-'.$threadId, 'state' => 'completed']]];
        }
    });
    rm_watch(null);
    app()->instance(TaskWorkspaceStateReader::class, new class implements TaskWorkspaceStateReader
    {
        public function headCommit(Instance $instance): ?string
        {
            return null;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return $instance->name;
        }
    });

    return $runtime;
}

function rm_watch(?TaskPullRequestHealth $health, TaskReviewReadStatus|TaskReviewObservation $reviews = TaskReviewReadStatus::Disabled): void
{
    app()->instance(TaskPullRequestWatcher::class, new readonly class($health) implements TaskPullRequestWatcher
    {
        public function __construct(private ?TaskPullRequestHealth $health) {}

        public function status(Task $group): ?string
        {
            return $this->health?->state;
        }

        public function health(Task $group): ?TaskPullRequestHealth
        {
            return $this->health;
        }
    });
    $observation = $reviews instanceof TaskReviewObservation ? $reviews : new TaskReviewObservation($reviews);
    app()->instance(TaskPullRequestReviewWatcher::class, new FakeTaskPullRequestReviewWatcher($observation, DB::transactionLevel()));
}

function rm_open(string $head, bool $mergeable = true, bool $conflicts = false, bool $behind = false, string $base = 'main'): TaskPullRequestHealth
{
    return new TaskPullRequestHealth('open', problems: $conflicts ? ['It conflicts with main; merge main into the task branch and push.'] : [],
        baseRef: $base, conflicts: $conflicts, headSha: $head, pullRequestNumber: 42, mergeable: $mergeable, behind: $behind);
}

/** @param array<string, string> $deliverables */
function rm_receipt(string $outcome, string $summary = 'Checked.', array $deliverables = [], bool $pullRequest = false): string
{
    return json_encode(array_filter([
        'outcome' => $outcome, 'summary' => $summary, 'deliverables' => $deliverables === [] ? null : $deliverables,
        'pull_request' => $pullRequest ? ['summary' => 'Adds the feature.', 'changes' => ['Ships it.'], 'breaking' => []] : null,
        'nonce' => bin2hex(random_bytes(8)),
    ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
}

function rm_settled(?string $prBranch = null): Task
{
    $group = rm_group([['Feature', TaskStatus::Completed]], TaskGroupStatus::Settling, prUrl: RM_PR_URL, prBranch: $prBranch);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_HEAD, 'source' => TaskReviewedCommitSource::OrbitPush, 'pushed_at' => now()]);
    $feature = $group->tasks->sole();
    $feature->update(['implementer_agent_thread_id' => test_agent_thread($group, 'implementer-'.$feature->id, $feature)->id]);

    return $group;
}

/** @param  array<string, mixed>  $overrides */
function rm_listed(int $number, array $overrides = []): GitHubListedPullRequest
{
    return new GitHubListedPullRequest(...[
        'number' => $number, 'url' => 'https://github.com/acme/orbit/pull/'.$number, 'title' => 'Change '.$number, 'body' => 'Body '.$number,
        'authorId' => 123, 'authorLogin' => 'maintainer', 'headRef' => 'feature/'.$number, 'headSha' => RM_PR_HEAD,
        'headRepository' => 'acme/orbit', 'baseRef' => 'main', 'draft' => false, ...$overrides,
    ]);
}

/** @return list<string> */
function rm_activity(Task $group): array
{
    return Activity::query()->where('subject_id', $group->id)->where('log_name', 'tasks')->orderBy('id')->pluck('description')->all();
}

describe('final review before every push', function (): void {
    it('limits both final review briefs to new blocking findings', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed]]);
        $rule = 'Block only on findings that meet the blocking rule and are new since the subtask approvals. Do not reopen hardening that the subtask reviews already covered.';

        expect(TaskFinalReview::brief($group))->toContain($rule);

        $group->pr_url = RM_PR_URL;
        $group->pr_branch = 'incoming-feature';

        expect($group->reviewsIncomingPullRequest())->toBeTrue()
            ->and(TaskFinalReview::brief($group))->toContain($rule);
    });

    it('commits an approved subtask without pushing it and starts the next subtask', function (): void {
        $group = rm_group([['Models', TaskStatus::Reviewing], ['Routes', TaskStatus::Todo]]);
        $first = $group->tasks->firstWhere('title', 'Models');
        rm_reviewing($first);
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['tests' => 'ModelsTest'])]);

        app(TaskScheduler::class)->tick();

        expect($first->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($first->comments()->sole()->commit_sha)->toBe(RM_COMMIT)
            ->and($runtime->pushes)->toBe([])
            ->and($group->tasks->firstWhere('title', 'Routes')?->fresh()?->status)->toBe(TaskStatus::Running);
    });

    it('appends a final review after the last approval and starts a fresh reviewer over the whole branch', function (): void {
        $group = rm_group([['Models', TaskStatus::Reviewing]]);
        $last = $group->tasks->sole();
        rm_reviewing($last);
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['tests' => 'ModelsTest'])]);

        app(TaskScheduler::class)->tick();

        $final = Task::query()->where('parent_id', $group->id)->where('type', TaskType::FinalReview->value)->sole();
        expect($last->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($last->comments()->sole()->pull_request)->toBeNull()
            ->and($runtime->pushes)->toBe([])
            ->and($runtime->bodies)->toBe([])
            ->and($final->status)->toBe(TaskStatus::Reviewing)
            ->and($final->title)->toBe(TaskFinalReview::Title)
            ->and($final->subtask_start_commit)->toBe(RM_BASE)
            ->and($final->review_workspace_head)->toBe(RM_COMMIT)
            ->and($final->opensPullRequest())->toBeTrue()
            ->and($runtime->reviewers)->toBe([$final->id])
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
            ->and(rm_activity($group))->toContain('final review appended');
    });

    it('records, pushes, and opens the pull request only when the final review approves', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]]);
        $final = $group->tasks->firstWhere('title', TaskFinalReview::Title);
        rm_reviewing($final, RM_COMMIT);
        $runtime = rm_runtime([rm_receipt('approved', 'The whole branch is ready.', ['final-review' => 'Read the full diff.'], pullRequest: true)]);

        app(TaskScheduler::class)->tick();

        $reviewed = TaskReviewedCommit::query()->where('task_id', $group->id)->sole();
        expect($runtime->pushes)->toBe([RM_COMMIT])
            ->and($runtime->branches)->toBe(['task-'.$group->id])
            ->and($runtime->bodies)->toHaveCount(1)
            ->and($runtime->bodies[0])->toContain('Adds the feature.')
            ->and($group->fresh()?->pr_url)->toBe(RM_PR_URL)
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview)
            ->and($final->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($reviewed->sha)->toBe(RM_COMMIT)
            ->and($reviewed->source)->toBe(TaskReviewedCommitSource::OrbitPush)
            ->and($reviewed->review_task_id)->toBe($final->id)
            ->and($reviewed->pushed_at)->not->toBeNull()
            ->and($runtime->reviews)->toBe([])
            ->and(rm_activity($group))->toContain('final review approved', 'reviewed commit pushed');
    });

    it('keeps the final review open and retries when the reviewed push fails', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]]);
        $final = $group->tasks->firstWhere('title', TaskFinalReview::Title);
        rm_reviewing($final, RM_COMMIT);
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['final-review' => 'Read it.'], pullRequest: true)]);
        $runtime->pushFailures = 1;

        app(TaskScheduler::class)->tick();

        expect($final->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($runtime->pushes)->toBe([])
            ->and(TaskReviewedCommit::query()->where('task_id', $group->id)->sole()->pushed_at)->toBeNull();

        Cache::flush();
        app(TaskScheduler::class)->tick();

        expect($runtime->pushes)->toBe([RM_COMMIT])
            ->and($final->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($group->fresh()?->pr_url)->toBe(RM_PR_URL);
    });

    it('turns requested changes into a fixup subtask without pushing', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]]);
        $final = $group->tasks->firstWhere('title', TaskFinalReview::Title);
        rm_reviewing($final, RM_COMMIT);
        $runtime = rm_runtime([rm_receipt('changes_requested', 'app/Models/Task.php: the scope misses legacy rows.')]);

        app(TaskScheduler::class)->tick();

        $fixup = Task::query()->where('parent_id', $group->id)->where('fixup_problem', TaskFinalReview::FixupProblem)->sole();
        expect($final->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($fixup->status)->toBe(TaskStatus::Running)
            ->and($fixup->title)->toBe(TaskFinalReview::FixupTitle)
            ->and($fixup->brief)->toContain('app/Models/Task.php: the scope misses legacy rows.')
            ->and($fixup->brief)->toContain('Fix each blocking finding of final review subtask #'.$final->id.' at its root cause', 'List follow-ups in the handoff instead of implementing them.')
            ->and($fixup->deliverables[0]['description'])->toBe('Each blocking finding of the final review is addressed or explicitly resolved within the task brief. Follow-ups are listed in the handoff.')
            ->and(array_column($fixup->deliverables ?? [], 'id'))->toBe(['final-review-findings'])
            ->and($runtime->implementers)->toBe([$fixup->id])
            ->and($runtime->pushes)->toBe([])
            ->and($runtime->reviews)->toBe([])
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
            ->and(rm_activity($group))->toContain('final review requested changes');
    });

    it('asks for assistance instead of a third final-review fixup in one window', function (): void {
        $group = rm_group([
            ['Models', TaskStatus::Completed],
            [TaskFinalReview::FixupTitle, TaskStatus::Completed], [TaskFinalReview::FixupTitle, TaskStatus::Completed],
            [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview],
        ]);
        Task::query()->where('parent_id', $group->id)->where('title', TaskFinalReview::FixupTitle)->update(['fixup_problem' => TaskFinalReview::FixupProblem]);
        $final = $group->tasks->firstWhere('title', TaskFinalReview::Title);
        rm_reviewing($final, RM_COMMIT);
        rm_runtime([rm_receipt('changes_requested', 'Still wrong.')]);

        app(TaskScheduler::class)->tick();

        expect(Task::query()->where('parent_id', $group->id)->where('fixup_problem', TaskFinalReview::FixupProblem)->count())->toBe(2)
            ->and($final->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
            ->and($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_reason)->toStartWith(TaskFinalReview::FixupCapPrefix);

        app(TaskScheduler::class)->tick();

        expect(Task::query()->where('parent_id', $group->id)->where('type', TaskType::FinalReview->value)->count())->toBe(1);
    });

    it('resumes an operator subtask appended after the final-review cap and clears that request', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling);
        $models = $group->tasks->firstWhere('title', 'Models');
        $models->update(['implementer_agent_thread_id' => test_agent_thread($group, 'implementer-'.$models->id, $models)->id]);
        TaskComment::query()->create(['task_id' => $models->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Fine.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
        test_update_task($group, ['assistance_requested' => true, 'assistance_kind' => AssistanceKind::Failure, 'assistance_reason' => TaskFinalReview::FixupCapPrefix.'Read the findings.']);
        $operator = Task::query()->create([
            'parent_id' => $group->id, 'position' => 3, 'title' => 'Rework the scope', 'brief' => 'Do it the other way.', 'status' => TaskStatus::Todo,
            'deliverables' => [['id' => 'tests', 'type' => 'review', 'description' => 'Tests cover it.']],
        ]);
        $runtime = rm_runtime();

        app(TaskScheduler::class)->tick();

        expect($operator->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($runtime->implementers)->toBe([$operator->id])
            ->and($group->fresh()?->assistance_requested)->toBeFalse();
    });

    it('appends a final review to a settling task whose fixup approval is not yet reviewed', function (): void {
        $group = rm_settled();
        $fixup = Task::query()->create([
            'parent_id' => $group->id, 'position' => 2, 'title' => 'Fix Required checks', 'brief' => 'Fix CI.',
            'status' => TaskStatus::Completed, 'fixup_problem' => 'check:Required checks', 'deliverables' => [['id' => 'project-check', 'type' => 'review', 'description' => 'CI passes.']],
        ]);
        TaskComment::query()->create(['task_id' => $fixup->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Fixed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        $final = Task::query()->where('parent_id', $group->id)->where('type', TaskType::FinalReview->value)->sole();
        expect($final->status)->toBe(TaskStatus::Reviewing)
            ->and($final->opensPullRequest())->toBeFalse()
            ->and($runtime->merges)->toBe([])
            ->and($runtime->pushes)->toBe([]);
    });

    it('does not append another final review when the approved HEAD moved past the latest approval', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling, prUrl: RM_PR_URL);
        $models = $group->tasks->firstWhere('title', 'Models');
        $final = $group->tasks->firstWhere('title', TaskFinalReview::Title);
        TaskComment::query()->create(['task_id' => $models->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Fine.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
        TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Whole branch.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_HEAD]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_HEAD, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $final->id, 'pushed_at' => now()]);
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));

        expect(TaskFinalReview::hasUnreviewedWork($group))->toBeFalse();

        app(TaskScheduler::class)->tick();

        expect(Task::query()->where('parent_id', $group->id)->where('type', TaskType::FinalReview->value)->count())->toBe(1)
            ->and($runtime->merges)->toBe([RM_HEAD]);
    });

    it('merges a conflict into a fixup instead of asking GitHub to update the branch', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD, mergeable: false, conflicts: true));

        app(TaskScheduler::class)->tick();

        expect($runtime->updates)->toBe([])
            ->and($runtime->merges)->toBe([])
            ->and(Task::query()->where('parent_id', $group->id)->where('fixup_problem', 'conflict:main')->exists())->toBeTrue();
    });

    it('does not ask GitHub to update a behind branch', function (): void {
        rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD, behind: true));

        app(TaskScheduler::class)->tick();

        expect($runtime->updates)->toBe([])
            ->and($runtime->merges)->toBe([RM_HEAD]);
    });

    it('cancels without pushing approved work that no final review approved', function (): void {
        $group = rm_group([['Models', TaskStatus::Completed]], TaskGroupStatus::Settling);
        TaskComment::query()->create(['task_id' => $group->tasks->sole()->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Fine.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);

        expect(TaskFinalReview::reconcileRemovalPush($group))->toBeNull();

        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_COMMIT, 'source' => TaskReviewedCommitSource::OrbitPush]);

        expect(TaskFinalReview::reconcileRemovalPush($group))->toBe(RM_COMMIT);
    });

    it('leaves a Project without the flow on the existing publish path', function (): void {
        $group = rm_group([['Models', TaskStatus::Reviewing], ['Routes', TaskStatus::Todo]], flow: false);
        rm_reviewing($group->tasks->firstWhere('title', 'Models'));
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['tests' => 'ModelsTest'])]);

        app(TaskScheduler::class)->tick();

        expect($runtime->pushes)->toBe([RM_COMMIT])
            ->and(Task::query()->where('parent_id', $group->id)->where('type', TaskType::FinalReview->value)->exists())->toBeFalse();
    });
});

describe('merge on green', function (): void {
    it('merges a reviewed, green, mergeable head through the App', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([RM_HEAD])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Merged)
            ->and($group->fresh()?->merged_sha)->toBe(str_repeat('f', 40))
            ->and(rm_activity($group))->toContain('pull request merged');
    });

    it('waits while the merge check is pending and records the reason once', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        $runtime->check = RequiredCheckState::Pending;
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();
        Cache::flush();
        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Waiting)
            ->and($group->fresh()?->merge_reason)->toBe('Check Required checks is still running on '.RM_HEAD.'.')
            ->and(array_count_values(rm_activity($group))['merge waiting'] ?? 0)->toBe(1);
    });

    it('refuses a head whose merge check failed', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        $runtime->check = RequiredCheckState::Failed;
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Refused);
    });

    it('waits while GitHub has not reported the pull request mergeable', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD, mergeable: false));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Waiting);
    });

    it('refuses while a trusted reviewer requests changes on any head', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/orbit.git');
        $trust = TaskReviewTrust::fromConfig($repository, 'acme/orbit:123');
        $review = new GitHubReview(91, 123, 'maintainer', GitHubReviewState::ChangesRequested, str_repeat('9', 40), new DateTimeImmutable('2026-10-08T10:00:00Z'), 'https://github.com/acme/orbit/pull/42#pullrequestreview-91', 'Please change it.');
        rm_watch(rm_open(RM_HEAD), new TaskReviewObservation(TaskReviewReadStatus::Complete, $repository, 42, $trust, null, [$review], TaskReviewSelection::select([$review], $trust, RM_HEAD, true)));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Refused)
            ->and($group->fresh()?->merge_reason)->toContain('maintainer requested changes in review 91');
    });

    it('waits when the reviews could not be read completely', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD), TaskReviewReadStatus::Unreadable);

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Waiting);
    });

    it('records a merge GitHub refuses', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        $runtime->mergeAccepted = false;
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([RM_HEAD])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Refused)
            ->and($group->fresh()?->merge_reason)->toContain('GitHub refused the merge');
    });

    it('asks for assistance and does not merge a head someone else pushed to an Orbit task branch', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_PR_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->fresh()?->assistance_reason)->toStartWith(TaskFinalReview::UnreviewedHeadPrefix)
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Refused);
    });

    it('refuses to merge into a base other than the default branch it reviewed against', function (): void {
        $group = rm_settled();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD, base: 'production'));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Refused)
            ->and($group->fresh()?->merge_reason)->toContain('base is production');
    });

    it('does not merge an older reviewed head while newer approved work waits for its final review', function (): void {
        $group = rm_settled();
        $feature = $group->tasks->sole();
        TaskComment::query()->create(['task_id' => $feature->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Fine.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));
        expect(TaskFinalReview::hasUnreviewedWork($group))->toBeTrue();

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([]);
    });

    it('clears the unreviewed-head request once the head is reviewed again, and lets an operator subtask resume past it', function (): void {
        $group = rm_settled();
        $feature = $group->tasks->sole();
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_PR_HEAD));

        app(TaskScheduler::class)->tick();

        expect(TaskFinalReview::isUnreviewedHeadReason($group->fresh()?->assistance_reason))->toBeTrue();

        $operator = Task::query()->create([
            'parent_id' => $group->id, 'position' => 2, 'title' => 'Take over the branch', 'brief' => 'Review the pushed commit.', 'status' => TaskStatus::Todo,
            'deliverables' => [['id' => 'tests', 'type' => 'review', 'description' => 'Tests cover it.']],
        ]);

        app(TaskScheduler::class)->tick();

        expect($operator->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($runtime->implementers)->toBe([$operator->id])
            ->and($group->fresh()?->assistance_requested)->toBeFalse();

        Task::query()->whereKey($operator->id)->update(['status' => TaskStatus::Cancelled->value]);
        test_update_task($group->refresh(), ['status' => TaskGroupStatus::Settling, 'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Failure,
            'assistance_reason' => TaskFinalReview::UnreviewedHeadPrefix.'someone pushed.']);
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->assistance_requested)->toBeFalse()
            ->and($runtime->merges)->toBe([RM_HEAD]);
    });

    it('does nothing for a Project without the flow', function (): void {
        $group = rm_group([['Feature', TaskStatus::Completed]], TaskGroupStatus::Settling, flow: false, prUrl: RM_PR_URL);
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([])
            ->and($group->fresh()?->merge_status)->toBeNull();
    });
});

describe('incoming pull requests', function (): void {
    it('reviews a new head on an incoming pull request again instead of merging it', function (): void {
        $group = rm_settled('feature/login');
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_PR_HEAD));

        app(TaskScheduler::class)->tick();

        $final = Task::query()->where('parent_id', $group->id)->where('type', TaskType::FinalReview->value)->sole();
        expect($runtime->merges)->toBe([])
            ->and($final->fixup_head_sha)->toBe(RM_PR_HEAD)
            ->and($final->status)->toBe(TaskStatus::Reviewing)
            ->and($runtime->moves)->toBe([RM_PR_HEAD])
            ->and($final->review_workspace_head)->toBe(RM_PR_HEAD)
            ->and($group->fresh()?->assistance_requested)->toBeFalse();
    });

    it('approves an unchanged incoming head on GitHub without pushing it', function (): void {
        $group = rm_group([[TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]], prUrl: RM_PR_URL, prBranch: 'feature/login');
        $final = $group->tasks->sole();
        rm_reviewing($final, RM_PR_HEAD, RM_PR_HEAD);
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['final-review' => 'Read it all.'])]);

        app(TaskScheduler::class)->tick();

        $reviewed = TaskReviewedCommit::query()->where('task_id', $group->id)->sole();
        expect($runtime->pushes)->toBe([])
            ->and($runtime->reviews)->toBe([[RM_PR_HEAD, 'APPROVE']])
            ->and($reviewed->source)->toBe(TaskReviewedCommitSource::PullRequestReview)
            ->and($reviewed->github_review_id)->toBe(7001)
            ->and($final->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::WaitingForReview)
            ->and(rm_activity($group))->toContain('pull request approved');
    });

    it('posts the findings as requested changes and applies them itself', function (): void {
        $group = rm_group([[TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]], prUrl: RM_PR_URL, prBranch: 'feature/login');
        $final = $group->tasks->sole();
        rm_reviewing($final, RM_PR_HEAD, RM_PR_HEAD);
        $runtime = rm_runtime([rm_receipt('changes_requested', 'Add a test for the login throttle.')]);

        app(TaskScheduler::class)->tick();

        expect($runtime->reviews)->toBe([[RM_PR_HEAD, 'REQUEST_CHANGES']])
            ->and(Task::query()->where('parent_id', $group->id)->where('fixup_problem', TaskFinalReview::FixupProblem)->sole()->status)->toBe(TaskStatus::Running)
            ->and($runtime->pushes)->toBe([]);
    });

    it('pushes Orbit\'s reviewed fixups to the pull request branch and approves that head', function (): void {
        $group = rm_group([[TaskFinalReview::Title, TaskStatus::Completed, TaskType::FinalReview], [TaskFinalReview::FixupTitle, TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]], prUrl: RM_PR_URL, prBranch: 'feature/login');
        $final = Task::query()->where('parent_id', $group->id)->where('status', TaskStatus::Reviewing->value)->sole();
        rm_reviewing($final, RM_COMMIT, RM_PR_HEAD);
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['final-review' => 'Read it all.'])]);
        rm_watch(rm_open(RM_PR_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->pushes)->toBe([RM_COMMIT])
            ->and($runtime->branches)->toBe(['feature/login'])
            ->and($runtime->bodies)->toBe([])
            ->and($runtime->reviews)->toBe([[RM_COMMIT, 'APPROVE']])
            ->and(TaskReviewedCommit::query()->where('task_id', $group->id)->sole()->source)->toBe(TaskReviewedCommitSource::OrbitPush);
    });

    it('reviews the head the pull request has when the final review starts', function (): void {
        $group = rm_group([[TaskFinalReview::Title, TaskStatus::Running, TaskType::FinalReview]], TaskGroupStatus::Running, prUrl: RM_PR_URL, prBranch: 'feature/login');
        $final = $group->tasks->sole();
        $final->update(['fixup_head_sha' => RM_PR_HEAD]);
        TaskCheck::query()->create([
            'task_id' => $final->id, 'kind' => TaskCheckKind::Baseline, 'status' => TaskCheckStatus::Passed,
            'pid' => 4001, 'process_started' => 'Wed Sep 23 12:00:01 2026', 'head_before' => RM_BASE, 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
        ]);
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        expect($runtime->moves)->toBe([RM_HEAD])
            ->and($final->fresh()?->fixup_head_sha)->toBe(RM_HEAD)
            ->and($final->fresh()?->status)->toBe(TaskStatus::Reviewing);
    });

    it('merges the author\'s new commits through a fixup when its reviewed push is no longer a fast-forward', function (): void {
        $group = rm_group([[TaskFinalReview::Title, TaskStatus::Completed, TaskType::FinalReview], [TaskFinalReview::FixupTitle, TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]], prUrl: RM_PR_URL, prBranch: 'feature/login');
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_PR_HEAD, 'source' => TaskReviewedCommitSource::PullRequestReview]);
        $final = Task::query()->where('parent_id', $group->id)->where('status', TaskStatus::Reviewing->value)->sole();
        rm_reviewing($final, RM_COMMIT);
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['final-review' => 'Read it all.'])]);
        $runtime->pushFailures = 1;
        rm_watch(rm_open(RM_HEAD));

        app(TaskScheduler::class)->tick();

        $merge = Task::query()->where('parent_id', $group->id)->where('title', 'Merge origin/feature/login')->sole();
        expect($runtime->pushes)->toBe([])
            ->and($runtime->reviews)->toBe([])
            ->and($final->fresh()?->status)->toBe(TaskStatus::Completed)
            ->and($merge->status)->toBe(TaskStatus::Running)
            ->and($merge->fixup_problem)->toBe(TaskFinalReview::FixupProblem);
    });

    it('keeps holding pushes on an incoming pull request after the Project turns the flow off', function (): void {
        $group = rm_group([['Fix', TaskStatus::Reviewing], ['Next', TaskStatus::Todo]], flow: false, prUrl: RM_PR_URL, prBranch: 'feature/login');
        rm_reviewing($group->tasks->firstWhere('title', 'Fix'));
        $runtime = rm_runtime([rm_receipt('approved', deliverables: ['tests' => 'FixTest'])]);

        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->reviewsBeforePush())->toBeTrue()
            ->and($runtime->pushes)->toBe([]);
    });

    it('runs the baseline before an incoming pull request\'s first final review, then moves to its head', function (): void {
        $group = rm_group([[TaskFinalReview::Title, TaskStatus::Running, TaskType::FinalReview]], TaskGroupStatus::Running, prUrl: RM_PR_URL, prBranch: 'feature/login');
        $final = $group->tasks->sole();
        $final->update(['fixup_head_sha' => RM_PR_HEAD]);
        $runtime = rm_runtime();
        $checks = app(TaskCheckRunner::class);

        app(TaskScheduler::class)->tick();

        expect($checks->starts)->toBe(1)
            ->and($runtime->moves)->toBe([]);

        app(TaskScheduler::class)->tick();

        expect($runtime->moves)->toBe([RM_PR_HEAD])
            ->and($final->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($final->fresh()?->subtask_start_commit)->toBe(RM_BASE)
            ->and($runtime->reviewers)->toBe([$final->id]);
    });
});

describe('incoming pull request watch', function (): void {
    it('creates one review task for each eligible pull request of a listed author', function (): void {
        $project = rm_project();
        config(['orbit.tasks.pull_request_authors' => 'acme/orbit:123']);
        $runtime = rm_runtime();
        $runtime->open = [
            rm_listed(1),
            rm_listed(2, ['authorId' => 456]),
            rm_listed(3, ['draft' => true]),
            rm_listed(4, ['headRepository' => 'someone/orbit']),
            rm_listed(5, ['baseRef' => 'release']),
            rm_listed(6, ['headRef' => 'task-9']),
        ];

        $created = app(WatchIncomingPullRequestsAction::class)->execute();

        $task = Task::topLevel()->where('project_id', $project->id)->sole();
        $final = $task->tasks()->sole();
        expect($created)->toHaveCount(1)
            ->and($task->pr_url)->toBe('https://github.com/acme/orbit/pull/1')
            ->and($task->pr_branch)->toBe('feature/1')
            ->and($task->status)->toBe(TaskGroupStatus::Todo)
            ->and($task->title)->toBe('Review #1: Change 1')
            ->and($task->brief)->toContain('Body 1')
            ->and($final->isFinalReview())->toBeTrue()
            ->and($final->fixup_head_sha)->toBe(RM_PR_HEAD)
            ->and($final->deliverables)->toBe(TaskFinalReview::deliverables());

        Cache::flush();

        expect(app(WatchIncomingPullRequestsAction::class)->execute())->toBe([]);
    });

    it('lists at most once a minute and not at all without listed authors', function (): void {
        rm_project();
        $runtime = rm_runtime();
        $runtime->open = [rm_listed(1)];

        expect(app(WatchIncomingPullRequestsAction::class)->execute())->toBe([]);

        config(['orbit.tasks.pull_request_authors' => 'acme/orbit:123']);
        expect(app(WatchIncomingPullRequestsAction::class)->execute())->toHaveCount(1);

        $runtime->open = [rm_listed(2)];
        expect(app(WatchIncomingPullRequestsAction::class)->execute())->toBe([]);
    });

    it('runs on every scheduler tick', function (): void {
        $project = rm_project();
        config(['orbit.tasks.pull_request_authors' => 'acme/orbit:123']);
        $runtime = rm_runtime();
        $runtime->open = [rm_listed(1)];

        $this->artisan('tasks:tick')->assertSuccessful();

        expect(Task::topLevel()->where('project_id', $project->id)->where('pr_url', 'https://github.com/acme/orbit/pull/1')->exists())->toBeTrue();
    });

    it('ignores a Project without the flow', function (): void {
        rm_project(flow: false);
        config(['orbit.tasks.pull_request_authors' => 'acme/orbit:123']);
        $runtime = rm_runtime();
        $runtime->open = [rm_listed(1)];

        expect(app(WatchIncomingPullRequestsAction::class)->execute())->toBe([]);
    });
});

describe('park failure recovery', function (): void {
    it('resumes a waiting group subtask after a failed park and a successful wake', function (): void {
        Queue::fake();
        $group = rm_group([['Feature', TaskStatus::Completed], ['Follow-up', TaskStatus::Todo]], TaskGroupStatus::WaitingForReview, prUrl: RM_PR_URL);
        test_vm_group($group);
        $vm = TaskVm::forGroup($group);
        $vm->update(['state' => TaskVmState::Parked]);
        rm_runtime();
        mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
        mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
        app()->instance(SshExecutor::class, new AppDevFakeSshExecutor);
        $provider = new RecordingTaskVmProvider;
        app()->instance(TaskVmProvider::class, $provider);
        Http::fake(['http://10.44.0.90:3774/capabilities' => Http::response(['models' => []], 200)]);
        $todo = $group->tasks->firstWhere('title', 'Follow-up');

        (new ParkTaskVm($vm->id))->failed(new RuntimeException('SSH stop failed'));
        expect(app(TaskScheduler::class)->claimNext())->toBeNull();
        Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
        app()->call([new ResumeTaskVm($vm->id), 'handle']);
        expect(app(TaskScheduler::class)->claimNext()?->id)->toBe($group->id);

        expect($vm->fresh())->state->toBe(TaskVmState::Ready)->error_code->toBeNull();
        expect($group->fresh())->status->toBe(TaskGroupStatus::Running)->assistance_requested->toBeFalse();
        expect($todo->fresh()?->status)->toBe(TaskStatus::Running);
        expect($provider->calls)->toBe(['start']);
    });

    it('still merges a reviewed green head while its waiting group has a park error', function (): void {
        $group = rm_settled();
        $group->update(['status' => TaskGroupStatus::WaitingForReview]);
        test_vm_group($group);
        $vm = TaskVm::forGroup($group);
        $vm->update(['state' => TaskVmState::Parked]);
        $runtime = rm_runtime();
        rm_watch(rm_open(RM_HEAD));

        (new ParkTaskVm($vm->id))->failed(new RuntimeException('SSH stop failed'));
        app(TaskScheduler::class)->tick();

        expect($runtime->merges)->toBe([RM_HEAD]);
        expect($group->fresh())->assistance_requested->toBeFalse()->merge_status->toBe(TaskMergeStatus::Merged);
        expect($vm->fresh())->state->toBe(TaskVmState::Parked)->error_code->toBe('task_vm.job_failed');
    });
});

it('scopes a final re-review to the prior final tree and findings until an operator adds work', function (): void {
    $group = rm_group([
        ['Final review', TaskStatus::Completed, TaskType::FinalReview],
        ['Address final review', TaskStatus::Completed],
        ['Final review', TaskStatus::Reviewing, TaskType::FinalReview],
    ]);
    [$previous, $fixup, $current] = $group->tasks->all();
    $previous->update(['review_workspace_tree' => str_repeat('b', 40)]);
    $fixup->update(['fixup_problem' => TaskFinalReview::FixupProblem]);
    $current->update(['subtask_start_commit' => RM_BASE]);
    TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $previous->id,
        'type' => 'changes_requested', 'body' => 'Fix the ordinary failure path.', 'author' => 'reviewer', 'posted_at' => now()]);
    $builder = app(TaskReviewPacketBuilder::class);

    $packet = $builder->build($current, false);

    expect($packet)->toContain('git diff --cached '.str_repeat('b', 40), 'This is a delta re-review', 'Fix the ordinary failure path.')
        ->and($builder->reviewContext($current))->toContain('Fix the ordinary failure path.');

    $fixup->update(['fixup_problem' => null]);
    $packet = $builder->build($current, false);
    expect($packet)->toContain('git diff '.RM_BASE)->not->toContain('This is a delta re-review');
});

it('starts the whole-branch final review without a local integration check', function (): void {
    $group = rm_group([['Feature', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Running, TaskType::FinalReview]]);
    $final = $group->tasks->firstWhere('type', TaskType::FinalReview);
    $runtime = rm_runtime();

    app(TaskScheduler::class)->tick();

    expect($final->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($final->fresh()?->subtask_start_commit)->toBe(RM_BASE)
        ->and($runtime->reviewers)->toBe([$final->id])
        ->and(TaskCheck::query()->count())->toBe(0);
});

it('pushes exactly the reviewed head without a pre-push integration check', function (): void {
    $group = rm_group([['Feature', TaskStatus::Completed], [TaskFinalReview::Title, TaskStatus::Reviewing, TaskType::FinalReview]]);
    $final = $group->tasks->firstWhere('type', TaskType::FinalReview);
    rm_reviewing($final, RM_COMMIT);
    $runtime = rm_runtime([rm_receipt('approved', deliverables: ['final-review' => 'Read the whole branch.'], pullRequest: true)]);

    app(TaskScheduler::class)->tick();

    expect($runtime->pushes)->toBe([RM_COMMIT])
        ->and($final->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and(TaskCheck::query()->count())->toBe(0)
        ->and(TaskReviewedCommit::query()->sole()->source)->toBe(TaskReviewedCommitSource::OrbitPush);
});

it('recognizes an exact merged approval but still holds newer missed work', function (bool $newer): void {
    $group = rm_group([['Final review', TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling, prUrl: RM_PR_URL);
    $final = $group->tasks->sole();
    $group->update(['settled_at' => now()]);
    TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_HEAD, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $final->id, 'pushed_at' => now()]);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_COMMIT, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $final->id, 'pushed_at' => now()]);
    if ($newer) {
        TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'New work.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_PR_HEAD]);
    }
    rm_runtime();
    rm_watch(new TaskPullRequestHealth('merged', headSha: RM_COMMIT));

    app(TaskScheduler::class)->settle($group);

    expect($group->fresh()?->assistance_requested)->toBe($newer);
})->with(['exact approval' => false, 'newer missed approval' => true]);

it('completes a merged reviewed head after main moved without repushing an older reviewed head', function (): void {
    $group = rm_group([['Final review', TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling, prUrl: RM_PR_URL);
    test_vm_group($group);
    app()->instance(TaskVmProvider::class, new RecordingTaskVmProvider);
    Queue::fake();
    $final = $group->tasks->sole();
    $instance = $group->taskable;
    $instance->update(['root' => 'public']);
    $group->update(['settled_at' => now()]);
    TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_HEAD, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $final->id]);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_COMMIT, 'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $final->id, 'pushed_at' => now()]);
    $runtime = rm_runtime();
    $runtime->pushFailures = 1;
    rm_watch(new TaskPullRequestHealth('merged', headSha: RM_COMMIT));

    app(TaskScheduler::class)->tick();

    expect($group->fresh()->assistance_reason)->toBeNull();
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed);
    expect($group->fresh()->assistance_requested)->toBeFalse();
    expect($runtime->pushes)->toBe([]);
    expect($runtime->pushFailures)->toBe(1);
    $this->assertModelMissing($instance);
});

it('completes a merged incoming pull request without pushing the author branch', function (): void {
    $group = rm_group([['Final review', TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling, prUrl: RM_PR_URL, prBranch: 'author/feature');
    test_vm_group($group);
    app()->instance(TaskVmProvider::class, new RecordingTaskVmProvider);
    Queue::fake();
    $final = $group->tasks->sole();
    $instance = $group->taskable;
    $instance->update(['root' => 'public']);
    $group->update(['settled_at' => now()]);
    TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_PR_HEAD]);
    TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => RM_PR_HEAD, 'source' => TaskReviewedCommitSource::PullRequestReview, 'review_task_id' => $final->id]);
    $runtime = rm_runtime();
    $runtime->pushFailures = 1;
    rm_watch(new TaskPullRequestHealth('merged', headSha: RM_PR_HEAD));

    app(TaskScheduler::class)->tick();

    expect($group->fresh()->assistance_reason)->toBeNull();
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed);
    expect($group->fresh()->assistance_requested)->toBeFalse();
    expect($runtime->pushes)->toBe([]);
    expect($runtime->pushFailures)->toBe(1);
    $this->assertModelMissing($instance);
});

it('keeps appended work when retrying merged cleanup', function (): void {
    $group = rm_group([['Appended work', TaskStatus::Todo]], TaskGroupStatus::Settling, prUrl: RM_PR_URL);
    $instance = $group->taskable;
    $group->update(['watched_pr_completion' => 'merged', 'settled_at' => now()]);
    TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.'unavailable');
    rm_runtime();

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
    app(TaskScheduler::class)->tick();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->tasks()->sole()->status)->toBe(TaskStatus::Todo)
        ->and($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toContain('open subtasks');
    $this->assertModelExists($instance);
});

it('asks once for assistance after GitHub refuses a behind branch without merging main', function (): void {
    $group = rm_settled();
    $runtime = rm_runtime();
    $runtime->mergeAccepted = false;
    rm_watch(rm_open(RM_HEAD, behind: true));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Refused)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe($group->fresh()?->merge_reason)
        ->and(TaskCheck::query()->count())->toBe(0)
        ->and($group->tasks()->count())->toBe(1)
        ->and($runtime->merges)->toBe([RM_HEAD]);
});

it('keeps a converted reviewed head mergeable and recognizes its original final approval on return', function (): void {
    $group = rm_settled();
    $final = $group->tasks->sole();
    TaskReviewedCommit::query()->where('task_id', $group->id)->delete();
    foreach ([RM_COMMIT, RM_HEAD] as $sha) {
        DB::table('task_reviewed_commits')->insert(['task_id' => $group->id, 'sha' => $sha,
            'source' => $sha === RM_HEAD ? 'base_merge' : 'orbit_push', 'review_task_id' => $final->id, 'pushed_at' => now()]);
    }
    TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $final->id,
        'type' => 'approved', 'body' => 'Approved the original head.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => RM_COMMIT]);
    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');
    $migration->up();
    $runtime = rm_runtime();
    rm_watch(rm_open(RM_HEAD));

    app(TaskScheduler::class)->tick();

    expect($runtime->merges)->toBe([RM_HEAD])
        ->and($group->fresh()?->merge_status)->toBe(TaskMergeStatus::Merged)
        ->and(app(TaskPublication::class)->checkReturningPullRequest($group->fresh(), new TaskPullRequestHealth('merged', headSha: RM_HEAD)))->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('does not hide a real orphan when a native reviewed head and a later approval share one review task', function (): void {
    $group = rm_group([['Final review', TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling, prUrl: RM_PR_URL);
    $final = $group->tasks->sole();
    foreach ([RM_HEAD, RM_COMMIT] as $sha) {
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha,
            'source' => TaskReviewedCommitSource::OrbitPush, 'review_task_id' => $final->id, 'pushed_at' => now()]);
        TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id,
            'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
    }

    expect(app(TaskPublication::class)->checkReturningPullRequest($group, new TaskPullRequestHealth('merged', headSha: RM_HEAD)))->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toStartWith(TaskScheduler::OrphanedCommitPrefix);
});

it('does not infer an inherited approval from ambiguous converted history', function (): void {
    $group = rm_group([['Final review', TaskStatus::Completed, TaskType::FinalReview]], TaskGroupStatus::Settling, prUrl: RM_PR_URL);
    $final = $group->tasks->sole();
    foreach ([RM_HEAD, RM_COMMIT] as $sha) {
        DB::table('task_reviewed_commits')->insert(['task_id' => $group->id, 'sha' => $sha,
            'source' => $sha === RM_HEAD ? 'base_merge' : 'orbit_push', 'review_task_id' => $final->id, 'pushed_at' => now()]);
        TaskComment::query()->create(['task_id' => $final->id, 'task_group_id' => $group->id,
            'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
    }
    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');
    $migration->up();

    expect(app(TaskPublication::class)->checkReturningPullRequest($group, new TaskPullRequestHealth('merged', headSha: RM_HEAD)))->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toStartWith(TaskScheduler::OrphanedCommitPrefix);
});
