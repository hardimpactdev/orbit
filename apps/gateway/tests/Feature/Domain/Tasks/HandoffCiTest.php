<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubMergeResult;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskHandoffCi;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AgentCommandDispatcher;

const CI_START = '5555555555555555555555555555555555555555';
const CI_HANDOFF = 'cccccccccccccccccccccccccccccccccccccccc';
const CI_EARLIER = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

beforeEach(function (): void {
    tick_bind_defaults();
});

/**
 * GitHub, the workspace commit, and CI for a handoff. `runs` maps a commit to the check runs GitHub reports on it.
 */
function ci_github(?string $commit = CI_HANDOFF): object
{
    $github = new class($commit) implements TaskPullRequestCheckOutput, TaskPullRequestMerger, TaskPullRequestPublisher, TaskPullRequestWatcher, TaskWorkspaceSigner
    {
        /** The pull request head GitHub reports, for a refused push. */
        public ?string $head = null;

        public bool $refused = false;

        /** @var array<string, list<GitHubCheckRun>> */
        public array $runs = [];

        /** @var list<string> */
        public array $messages = [];

        /** @var list<string> */
        public array $handoffs = [];

        /** @var list<string> */
        public array $reads = [];

        public bool $conflicts = false;

        public bool $pending = false;

        public function __construct(public ?string $commit) {}

        public function commit(Instance $instance, string $message): ?string
        {
            $this->messages[] = $message;

            return $this->commit;
        }

        public function checkRuns(Task $group, string $sha): array
        {
            $this->reads[] = $sha;

            return $this->runs[$sha] ?? [];
        }

        public function publishHandoff(Task $group, string $commit): GitHubPullRequest
        {
            $this->handoffs[] = $commit;
            if ($this->pending) {
                throw TaskPullRequestException::pending('Orbit is pushing commit '.$commit.'.');
            }
            if ($this->refused) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }

            return new GitHubPullRequest(GitHubPullRequestState::Open, ! $this->conflicts, $this->conflicts ? 'dirty' : 'clean', $commit, 'main');
        }

        public function releaseHandoffDraft(Task $group): void {}

        public function status(Task $group): ?string
        {
            return 'open';
        }

        public function health(Task $group): ?TaskPullRequestHealth
        {
            return new TaskPullRequestHealth('open', headSha: $this->head);
        }

        public function read(Task $group, TaskPullRequestCheck $check): string
        {
            return "This is the CI evidence (a redacted excerpt of the failed check output):\n\n```\nFAILED Tests\\Feature\\ExportTest > it exports\n```";
        }

        public function publish(Task $group, string $body, string $commit): string
        {
            throw new LogicException('A handoff opens no ready pull request.');
        }

        public function push(Task $group, string $commit): void
        {
            throw new LogicException('A handoff publishes through publishHandoff.');
        }

        public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
        {
            throw new LogicException('A handoff reads every run on its commit.');
        }

        public function merge(Task $group, string $sha): GitHubMergeResult
        {
            throw new LogicException('A handoff merges nothing.');
        }

        public function review(Task $group, string $sha, GitHubReviewEvent $event, string $body): int
        {
            throw new LogicException('A handoff reviews nothing.');
        }

        /** @return list<GitHubListedPullRequest> */
        public function openPullRequests(Project $project): array
        {
            return [];
        }

        public function greenDefaultTipAfter(Task $group, string $sha, string $checkName): ?string
        {
            return null;
        }
    };
    foreach ([TaskWorkspaceSigner::class, TaskPullRequestMerger::class, TaskPullRequestPublisher::class, TaskPullRequestCheckOutput::class, TaskPullRequestWatcher::class] as $abstract) {
        app()->instance($abstract, $github);
    }

    return $github;
}

function ci_run(string $name, ?string $conclusion, string $sha, int $id = 1): GitHubCheckRun
{
    return new GitHubCheckRun($name, $conclusion, 'https://github.com/acme/orbit/actions/runs/9/job/'.$id, $id, null,
        $conclusion === null ? 'in_progress' : 'completed', $sha, RequiredCheckState::CheckApp);
}

/** A handoff of a subtask in a Project whose merge check is `Required checks`, with a passing task check. @return array{Task, Task} */
function ci_handoff(): array
{
    [$group, $task] = tick_deliverables([], [], null);
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git', 'merge_check' => 'Required checks']);

    return [$group->fresh() ?? $group, $task];
}

/** The check starts on the first tick, and its passed result is handled on the second. */
function ci_ticks(int $ticks = 2): void
{
    foreach (range(1, $ticks) as $_) {
        app(TaskScheduler::class)->tick();
    }
}

/** The once-a-minute read of one handoff's CI is due again. */
function ci_next_read(): void
{
    Cache::flush();
    app(TaskScheduler::class)->tick();
}

it('commits the checked tree and starts the review once the merge check passed on that commit', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2), ci_run('Required checks', 'success', CI_HANDOFF)];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($check->status)->toBe(TaskCheckStatus::Passed)
        ->and($check->commit_sha)->toBe(CI_HANDOFF)
        ->and($github->messages)->toBe(["Models\n\nDone."])
        ->and($github->reads)->toBe([CI_HANDOFF]);
});

it('publishes the handoff commit and waits while CI has no result on it', function (): void {
    $github = ci_github();
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($github->handoffs)->toBe([CI_HANDOFF])
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);

    $github->runs[CI_HANDOFF] = [ci_run('Required checks', null, CI_HANDOFF)];
    app(TaskScheduler::class)->tick();
    expect($github->reads)->toBe([CI_HANDOFF])->and($task->fresh()?->status)->toBe(TaskStatus::Running);

    ci_next_read();
    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($github->handoffs)->toBe([CI_HANDOFF])
        ->and($github->messages)->toHaveCount(1);

    $github->runs[CI_HANDOFF] = [ci_run('Required checks', 'success', CI_HANDOFF)];
    ci_next_read();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->messages)->toHaveCount(1);
});

it('waits without a failure while the push of the handoff commit runs', function (): void {
    $github = ci_github();
    $github->pending = true;
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->communication_failures)->toBe(0)
        ->and($github->handoffs)->toBe([CI_HANDOFF]);
});

it('returns the failed jobs and their logs to the implementer as a failed handoff check', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [
        ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2),
        ci_run('Gateway shards (3)', 'failure', CI_HANDOFF, 4),
        ci_run('Required checks', 'failure', CI_HANDOFF),
    ];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    $reminder = app(AgentCommandDispatcher::class)->commands[0]['message']['text'] ?? '';
    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($check->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->failed_step)->toBe(TaskHandoffCi::FailedStep)
        ->and($check->commit_sha)->toBe(CI_HANDOFF)
        ->and($check->output)->toContain('Check Gateway shards (3) failed (https://github.com/acme/orbit/actions/runs/9/job/4).')
        ->and($check->output)->not->toContain('Check Required checks failed')
        ->and($reminder)->toContain('Orbit committed your work as '.CI_HANDOFF)
        ->and($reminder)->toContain('Check Required checks did not pass on that commit in CI.')
        ->and($reminder)->toContain('FAILED Tests\Feature\ExportTest > it exports');
});

it('counts a failed CI result toward the failed-check budget', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Required checks', 'failure', CI_HANDOFF)];
    [$group, $task] = ci_handoff();
    foreach (range(1, 3) as $attempt) {
        TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Failed, 'failed_step' => TaskHandoffCi::FailedStep,
            'pid' => $attempt, 'process_started' => 'earlier', 'head_before' => CI_START, 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
        ]);
    }

    ci_ticks();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toStartWith('The handoff check failed 4 times in a row');
});

it('does not count a passed result of an earlier handoff commit for the new one', function (): void {
    $github = ci_github();
    $github->runs[CI_EARLIER] = [ci_run('Required checks', 'success', CI_EARLIER)];
    $github->runs[CI_HANDOFF] = [ci_run('Required checks', null, CI_HANDOFF)];
    [, $task] = ci_handoff();
    TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'commit_sha' => CI_EARLIER,
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => CI_START, 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($github->reads)->toBe([CI_HANDOFF])
        ->and(TaskCheck::query()->latest('id')->firstOrFail()->commit_sha)->toBe(CI_HANDOFF);
});

it('asks the implementer to merge the base when the conflict keeps GitHub from running CI', function (): void {
    $github = ci_github();
    $github->conflicts = true;
    [, $task] = ci_handoff();

    ci_ticks();

    $reminder = app(AgentCommandDispatcher::class)->commands[0]['message']['text'] ?? '';
    expect(TaskCheck::query()->sole()->failed_step)->toBe(TaskHandoffCi::FailedStep)
        ->and($reminder)->toContain('GitHub reports that the task branch conflicts with main, so CI cannot run on '.CI_HANDOFF.'.')
        ->and($reminder)->toContain('Merge origin/main into the task branch');
});

it('passes a handoff that changed nothing, whose commit is the tested subtask start', function (): void {
    $github = ci_github(CI_START);
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->reads)->toBe([])
        ->and($github->handoffs)->toBe([]);
});

it('commits nothing and reads no CI for a Project without a merge check', function (): void {
    $github = ci_github();
    [, $task] = tick_deliverables([], [], null);

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->messages)->toBe([])
        ->and($github->reads)->toBe([])
        ->and(TaskCheck::query()->sole()->commit_sha)->toBeNull();
});

it('asks for assistance when GitHub stays unreadable', function (): void {
    $fake = ci_github();
    app()->instance(TaskPullRequestMerger::class, new class($fake) implements TaskPullRequestMerger
    {
        public function __construct(private TaskPullRequestMerger $inner) {}

        public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
        {
            return $this->inner->requiredCheck($group, $sha, $checkName);
        }

        public function checkRuns(Task $group, string $sha): array
        {
            throw new TaskPullRequestException('Orbit could not read the check runs of '.$sha.': GitHub is unavailable.');
        }

        public function merge(Task $group, string $sha): GitHubMergeResult
        {
            return $this->inner->merge($group, $sha);
        }

        public function review(Task $group, string $sha, GitHubReviewEvent $event, string $body): int
        {
            return $this->inner->review($group, $sha, $event, $body);
        }

        public function openPullRequests(Project $project): array
        {
            return $this->inner->openPullRequests($project);
        }

        public function greenDefaultTipAfter(Task $group, string $sha, string $checkName): ?string
        {
            return null;
        }
    });
    [, $task] = ci_handoff();

    ci_ticks();
    foreach (range(1, 4) as $_) {
        ci_next_read();
    }

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toContain('GitHub is unavailable')
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

it('asks the implementer to merge an incoming pull request branch its author pushed to meanwhile', function (): void {
    $github = ci_github();
    $github->refused = true;
    $github->head = str_repeat('f', 40);
    [$group, $task] = ci_handoff();
    $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/42', 'pr_branch' => 'cursor/login-throttle']);

    ci_ticks();

    $reminder = app(AgentCommandDispatcher::class)->commands[0]['message']['text'] ?? '';
    expect(TaskCheck::query()->sole()->failed_step)->toBe(TaskHandoffCi::FailedStep)
        ->and($reminder)->toContain('The pull request author pushed '.str_repeat('f', 40).' to cursor/login-throttle')
        ->and($reminder)->toContain('Merge origin/cursor/login-throttle into the task branch');
});

it('counts a refused push as a communication failure when the head is a commit Orbit knows', function (bool $incoming): void {
    $github = ci_github();
    $github->refused = true;
    $github->head = CI_EARLIER;
    [$group, $task] = ci_handoff();
    if ($incoming) {
        $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/42', 'pr_branch' => 'cursor/login-throttle']);
    }
    TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Failed, 'commit_sha' => CI_EARLIER,
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => CI_START, 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);

    ci_ticks();

    expect(TaskCheck::query()->latest('id')->firstOrFail()->status)->toBe(TaskCheckStatus::Passed)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
})->with(['incoming pull request' => true, 'task branch' => false]);
