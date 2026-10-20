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
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskHandoffCi;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\FakeTaskCheckRunner;

const CI_GROUP_START = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CI_START = '5555555555555555555555555555555555555555';
const CI_HANDOFF = 'cccccccccccccccccccccccccccccccccccccccc';
const CI_EARLIER = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const CI_CHECKED_TREE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    tick_bind_defaults();
});

/**
 * GitHub, the workspace commit, and CI for a handoff. `runs` maps a commit to the check runs GitHub reports on it.
 */
function ci_github(?string $commit = CI_HANDOFF): object
{
    $github = new class($commit) implements TaskPullRequestCheckOutput, TaskPullRequestMerger, TaskPullRequestPublisher, TaskWorkspaceSigner
    {
        /** @var array<string, list<GitHubCheckRun>> */
        public array $runs = [];

        /** @var list<string> */
        public array $messages = [];

        /** @var list<string> */
        public array $handoffs = [];

        public int $reads = 0;

        public bool $conflicts = false;

        public bool $pending = false;

        public bool $unreadable = false;

        /** The tree the commit holds; the checked tree unless a test moves it. */
        public string $tree = CI_CHECKED_TREE;

        public function __construct(public ?string $commit) {}

        public function commit(Instance $instance, string $message): ?string
        {
            $this->messages[] = $message;
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner && $this->commit !== null) {
                $checks->head = $this->commit;
                $checks->commitTree = $this->tree;
            }

            return $this->commit;
        }

        public function checkRuns(Task $group, string $sha): ?array
        {
            if ($this->unreadable) {
                throw new TaskPullRequestException('Orbit could not read the check runs of '.$sha.': GitHub is unavailable.');
            }
            $this->reads++;

            return $this->runs[$sha] ?? [];
        }

        public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
        {
            try {
                return RequiredCheckState::of($this->checkRuns($group, $sha) ?? [], $sha, $checkName);
            } catch (TaskPullRequestException) {
                return RequiredCheckState::Unreadable;
            }
        }

        public function pullRequest(Task $group, int $number): GitHubPullRequest
        {
            return new GitHubPullRequest(GitHubPullRequestState::Open, ! $this->conflicts, $this->conflicts ? 'dirty' : 'clean', CI_HANDOFF, 'main');
        }

        public function publishHandoff(Task $group, string $commit): int
        {
            if ($this->pending) {
                throw TaskPullRequestException::pending('Orbit is pushing commit '.$commit.'.');
            }
            $this->handoffs[] = $commit;

            return 7;
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

        public function releaseHandoffDraft(Task $group): void {}

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
    foreach ([TaskWorkspaceSigner::class, TaskPullRequestMerger::class, TaskPullRequestPublisher::class, TaskPullRequestCheckOutput::class] as $abstract) {
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

/** The check starts on the first tick, and its passed result is handled from the second. */
function ci_ticks(int $ticks = 2): void
{
    foreach (range(1, $ticks) as $_) {
        app(TaskScheduler::class)->tick();
    }
}

function ci_reminder(): string
{
    return app(AgentCommandDispatcher::class)->commands[0]['message']['text'] ?? '';
}

it('commits the checked tree, publishes it once, and starts the review once the merge check passed on that commit', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2), ci_run('Required checks', 'success', CI_HANDOFF)];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($check->status)->toBe(TaskCheckStatus::Passed)
        ->and($check->commit_sha)->toBe(CI_HANDOFF)
        ->and($check->pull_request_number)->toBe(7)
        ->and($github->messages)->toBe(["Models\n\nDone."])
        ->and($github->handoffs)->toBe([CI_HANDOFF]);
});

it('publishes the commit once and waits while the merge check has no run, until it passes', function (): void {
    $github = ci_github();
    [, $task] = ci_handoff();

    ci_ticks(4);

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($github->handoffs)->toBe([CI_HANDOFF])
        ->and($github->messages)->toHaveCount(1)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);

    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', null, CI_HANDOFF, 2)];
    ci_ticks(1);
    expect($task->fresh()?->status)->toBe(TaskStatus::Running);

    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2), ci_run('Required checks', 'success', CI_HANDOFF)];
    ci_ticks(1);
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->handoffs)->toBe([CI_HANDOFF]);
});

it('waits without a failure while the push of the handoff commit runs, then publishes it', function (): void {
    $github = ci_github();
    $github->pending = true;
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->communication_failures)->toBe(0)
        ->and(TaskCheck::query()->sole()->pull_request_number)->toBeNull()
        ->and($github->reads)->toBe(0);

    $github->pending = false;
    ci_ticks(1);
    expect($github->handoffs)->toBe([CI_HANDOFF]);
});

it('returns the failed jobs and their logs to the implementer as a failed handoff check', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [
        ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2),
        ci_run('Gateway shards (2)', 'cancelled', CI_HANDOFF, 3),
        ci_run('Gateway shards (3)', 'failure', CI_HANDOFF, 4),
        ci_run('Required checks', 'failure', CI_HANDOFF),
    ];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($check->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->failed_step)->toBe(TaskHandoffCi::FailedStep)
        ->and($check->output)->toContain('Check Gateway shards (3) failed (https://github.com/acme/orbit/actions/runs/9/job/4).')
        ->and($check->output)->not->toContain('Gateway shards (2)')
        ->and($check->output)->not->toContain('Check Required checks failed')
        ->and(ci_reminder())->toContain('Orbit committed your work as '.CI_HANDOFF)
        ->and(ci_reminder())->toContain('The handoff did not pass CI')
        ->and(ci_reminder())->toContain('FAILED Tests\Feature\ExportTest > it exports');
});

it('asks for assistance, not the implementer, when every failed job did not run to completion', function (string $conclusion): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (2)', $conclusion, CI_HANDOFF, 3), ci_run('Required checks', 'failure', CI_HANDOFF)];
    [, $task] = ci_handoff();

    ci_ticks();

    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Passed)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('CI did not run to completion on handoff commit '.CI_HANDOFF.': Gateway shards (2) ('.$conclusion.'). Re-run the jobs, then post a resolution so the implementer hands off again.')
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
})->with(['cancelled', 'startup_failure']);

it('counts a failed CI result toward the failed-check budget', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Required checks', 'failure', CI_HANDOFF)];
    [, $task] = ci_handoff();
    foreach (range(1, 3) as $attempt) {
        TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Failed, 'failed_step' => TaskHandoffCi::FailedStep,
            'pid' => $attempt, 'process_started' => 'earlier', 'head_before' => CI_START, 'tree_before' => CI_CHECKED_TREE, 'started_at' => now(),
        ]);
    }

    ci_ticks();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toStartWith('The handoff check failed 4 times in a row');
});

it('does not count a passed result of an earlier handoff commit for the new one', function (): void {
    $github = ci_github();
    $github->runs[CI_EARLIER] = [ci_run('Required checks', 'success', CI_EARLIER)];
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', null, CI_HANDOFF, 2)];
    [, $task] = ci_handoff();
    TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'commit_sha' => CI_EARLIER, 'pull_request_number' => 7,
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => CI_START, 'tree_before' => CI_CHECKED_TREE, 'started_at' => now(),
    ]);

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->latest('id')->firstOrFail()->commit_sha)->toBe(CI_HANDOFF);
});

it('reads the merge check of an unchanged handoff on its own commit, and a red one fails', function (): void {
    $github = ci_github(CI_START);
    $github->runs[CI_START] = [ci_run('Required checks', 'failure', CI_START)];
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($github->handoffs)->toBe([CI_START])
        ->and(TaskCheck::query()->sole()->failed_step)->toBe(TaskHandoffCi::FailedStep);
});

it('reads main\'s merge check on the group start commit without publishing it', function (array $runs, TaskStatus $status): void {
    $github = ci_github(CI_GROUP_START);
    $github->runs[CI_GROUP_START] = $runs;
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe($status)
        ->and($github->handoffs)->toBe([])
        ->and(TaskCheck::query()->sole()->pull_request_number)->toBeNull();
})->with([
    'green on main' => [[ci_run('Required checks', 'success', CI_GROUP_START)], TaskStatus::Reviewing],
    'no run on main yet' => [[], TaskStatus::Running],
]);

it('asks the implementer to merge the base when the conflict keeps GitHub from running CI', function (): void {
    $github = ci_github();
    $github->conflicts = true;
    [, $task] = ci_handoff();

    ci_ticks();

    expect(TaskCheck::query()->sole()->failed_step)->toBe(TaskHandoffCi::FailedStep)
        ->and(ci_reminder())->toContain('GitHub reports that the task branch conflicts with main, so CI cannot run on '.CI_HANDOFF.'.')
        ->and(ci_reminder())->toContain('Merge origin/main into the task branch');
});

it('fails the handoff when the commit does not hold the tree the check passed on, and publishes nothing', function (): void {
    $github = ci_github();
    $github->tree = str_repeat('d', 40);
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    expect($check->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->commit_sha)->toBe(CI_HANDOFF)
        ->and($check->output)->toContain('that commit holds tree '.str_repeat('d', 40).', not tree '.CI_CHECKED_TREE)
        ->and($github->handoffs)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

it('commits nothing and reads no CI for a Project without a merge check', function (): void {
    $github = ci_github();
    [, $task] = tick_deliverables([], [], null);

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->messages)->toBe([])
        ->and($github->reads)->toBe(0)
        ->and(TaskCheck::query()->sole()->commit_sha)->toBeNull();
});

it('asks for assistance when GitHub stays unreadable', function (): void {
    $github = ci_github();
    $github->unreadable = true;
    [, $task] = ci_handoff();

    ci_ticks(6);

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toContain('Orbit could not read the check runs of '.CI_HANDOFF)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});
