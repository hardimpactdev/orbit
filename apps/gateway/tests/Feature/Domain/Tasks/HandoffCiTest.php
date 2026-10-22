<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubMergeResult;
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
use App\Infrastructure\Tasks\GitHubTaskPullRequestMerger;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

const CI_START = '5555555555555555555555555555555555555555';
const CI_HANDOFF = 'cccccccccccccccccccccccccccccccccccccccc';
const CI_EARLIER = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const CI_CHECKED_TREE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    tick_bind_defaults();
});

/**
 * GitHub, the workspace commit, and CI for a handoff. `runs` and `suites` map a commit to the check runs and suites
 * GitHub reports on it.
 */
function ci_github(?string $commit = CI_HANDOFF): object
{
    $github = new class($commit) implements TaskPullRequestCheckOutput, TaskPullRequestMerger, TaskPullRequestPublisher, TaskWorkspaceSigner
    {
        /** @var array<string, list<GitHubCheckRun>> */
        public array $runs = [];

        /** @var array<string, list<GitHubCheckSuite>> */
        public array $suites = [];

        /** @var list<bool> */
        public array $allowEmpty = [];

        /** @var list<string> */
        public array $messages = [];

        /** @var list<string> the handoff commits pushed to task-{id}, each once, as the task VM row records them */
        public array $handoffs = [];

        public int $reads = 0;

        /** Whether the push job still runs. */
        public bool $pending = false;

        public bool $pushFails = false;

        public bool $unreadable = false;

        /** Whether the App lacks `checks: read`. */
        public bool $noChecksPermission = false;

        /** The tree the commit holds; the checked tree unless a test moves it. */
        public string $tree = CI_CHECKED_TREE;

        public function __construct(public ?string $commit) {}

        public function commit(Instance $instance, string $message, bool $allowEmpty = false): ?string
        {
            $this->messages[] = $message;
            $this->allowEmpty[] = $allowEmpty;
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner && $this->commit !== null) {
                $checks->head = $this->commit;
                $checks->commitTree = $this->tree;
            }

            return $this->commit;
        }

        public function checkRuns(Task $group, string $sha): ?array
        {
            return $this->commitChecks($group, $sha)['runs'] ?? null;
        }

        public function commitChecks(Task $group, string $sha): ?array
        {
            if ($this->unreadable) {
                throw new TaskPullRequestException('Orbit could not read the checks of '.$sha.': GitHub is unavailable.');
            }
            if ($this->noChecksPermission) {
                return null;
            }
            $this->reads++;

            return ['runs' => $this->runs[$sha] ?? [], 'suites' => $this->suites[$sha] ?? [], 'pull_request_suites' => []];
        }

        public function requiredCheck(Task $group, string $sha, string $checkName): RequiredCheckState
        {
            throw new LogicException('A handoff judges the snapshot itself.');
        }

        public function pullRequestCheck(Task $group, string $sha, string $checkName): RequiredCheckState
        {
            throw new LogicException('A handoff never reads a pull request.');
        }

        public function pushHandoff(Task $group, string $commit): void
        {
            if ($this->pending) {
                throw TaskPullRequestException::pending('Orbit is pushing commit '.$commit.' to task-'.$group->id.'-handoff.');
            }
            if ($this->pushFails) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
            if (! in_array($commit, $this->handoffs, true)) {
                $this->handoffs[] = $commit;
            }
        }

        public function deleteHandoffBranch(Task $group): void {}

        public function read(Task $group, TaskPullRequestCheck $check): string
        {
            return "This is the CI evidence (a redacted excerpt of the failed check output):\n\n```\nFAILED Tests\\Feature\\ExportTest > it exports\n```";
        }

        public function publish(Task $group, string $body, string $commit): string
        {
            throw new LogicException('A handoff opens no pull request.');
        }

        public function push(Task $group, string $commit): void
        {
            throw new LogicException('A handoff pushes through pushHandoff.');
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

/** A handoff of a subtask in a review-and-merge Project whose merge check is `Required checks`, with a passing task check. @return array{Task, Task} */
function ci_handoff(string $summary = 'Done.'): array
{
    [$group, $task] = tick_deliverables([], [], null, receipts: [FakeTaskTurnReceipts::contents('ready_for_review', $summary)]);
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git', 'merge_check' => 'Required checks', 'review_and_merge' => true]);

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

function ci_suite(?string $status, ?string $conclusion, int $id = 5): GitHubCheckSuite
{
    return new GitHubCheckSuite($id, $status, $conclusion, RequiredCheckState::CheckApp);
}

/** The reason Orbit gives when it asks for assistance, with the action every such reason ends with. */
function ci_assistance(string $reason): string
{
    return $reason.' Then post a resolution so the implementer hands off again.';
}

it('commits the checked tree, pushes it to task-{id}, and starts the review once the merge check passed on that commit', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2), ci_run('Required checks', 'success', CI_HANDOFF)];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($check->status)->toBe(TaskCheckStatus::Passed)
        ->and($check->commit_sha)->toBe(CI_HANDOFF)
        ->and($github->messages)->toBe(["Models\n\nDone."])
        ->and($github->handoffs)->toBe([CI_HANDOFF]);
});

it('waits while CI on the pushed commit has no merge-check run yet, until it passes', function (): void {
    $github = ci_github();
    [, $task] = ci_handoff();

    ci_ticks(4);

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($github->handoffs)->toBe([CI_HANDOFF])
        ->and($github->messages)->toHaveCount(1)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);

    $github->suites[CI_HANDOFF] = [ci_suite('in_progress', null)];
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', null, CI_HANDOFF, 2), ci_run('Required checks', null, CI_HANDOFF)];
    ci_ticks(1);
    expect($task->fresh()?->status)->toBe(TaskStatus::Running);

    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2), ci_run('Required checks', 'success', CI_HANDOFF)];
    ci_ticks(1);
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(0);
});

it('waits without a failure while the push of the handoff commit runs, and reads CI only after it', function (): void {
    $github = ci_github();
    $github->pending = true;
    [, $task] = ci_handoff();

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->communication_failures)->toBe(0)
        ->and($github->reads)->toBe(0);

    $github->pending = false;
    ci_ticks(1);
    expect($github->handoffs)->toBe([CI_HANDOFF])
        ->and($github->reads)->toBe(1);
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
        ->and($task->fresh()?->assistance_reason)->toBe(ci_assistance('CI did not run to completion on handoff commit '.CI_HANDOFF.': Gateway shards (2) ('.$conclusion.'). Check the runners.'))
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
})->with(['cancelled', 'startup_failure']);

it('asks for assistance, not the implementer, when the merge check did not pass but no job failed', function (GitHubCheckRun $run, string $named): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (1)', 'success', CI_HANDOFF, 2), $run];
    [, $task] = ci_handoff();

    ci_ticks();

    expect(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Passed)
        ->and($task->fresh()?->assistance_reason)->toBe(ci_assistance('Check Required checks did not pass on handoff commit '.CI_HANDOFF.', but no job failed: '.$named
            .'. A skipped, neutral, or stale run, or a run of that name from another App, causes this. Fix the workflow or the Project merge check.'))
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
})->with([
    'a skipped run' => [ci_run('Required checks', 'skipped', CI_HANDOFF), 'Required checks (github-actions, skipped)'],
    'a neutral run' => [ci_run('Required checks', 'neutral', CI_HANDOFF), 'Required checks (github-actions, neutral)'],
    'a stale run' => [ci_run('Required checks', 'stale', CI_HANDOFF), 'Required checks (github-actions, stale)'],
    'a run of another App' => [new GitHubCheckRun('Required checks', 'success', null, 9, null, 'completed', CI_HANDOFF, 'mintlify'), 'Required checks (mintlify, success)'],
]);

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
        'task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'commit_sha' => CI_EARLIER,
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => CI_START, 'tree_before' => CI_CHECKED_TREE, 'started_at' => now(),
    ]);

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->latest('id')->firstOrFail()->commit_sha)->toBe(CI_HANDOFF);
});

it('gives every handoff its own commit, also when nothing changed, and pushes and tests it', function (): void {
    $github = ci_github(CI_START);
    $github->runs[CI_START] = [ci_run('Required checks', 'failure', CI_START)];
    [, $task] = ci_handoff();

    ci_ticks();

    expect($github->allowEmpty)->toBe([true])
        ->and($github->handoffs)->toBe([CI_START])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->sole()->failed_step)->toBe(TaskHandoffCi::FailedStep);
});

it('decides a missing merge-check run from the commit\'s GitHub Actions suites', function (?string $status, ?string $conclusion, string $outcome): void {
    $github = ci_github();
    // Another App's suite and run, such as Mintlify's, never decide whether GitHub Actions ran.
    $github->runs[CI_HANDOFF] = [new GitHubCheckRun('Mintlify Deployment', 'success', null, 9, null, 'completed', CI_HANDOFF, 'mintlify')];
    $github->suites[CI_HANDOFF] = [new GitHubCheckSuite(8, 'completed', 'success', 'mintlify'), ...($status === null ? [] : [ci_suite($status, $conclusion)])];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    $fresh = $task->fresh();
    $waits = static fn () => expect($check->status)->toBe(TaskCheckStatus::Passed)->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
    match ($outcome) {
        'pending' => $waits()->and($fresh?->assistance_requested)->toBeFalse(),
        'implementer' => expect($check->failed_step)->toBe(TaskHandoffCi::FailedStep)
            ->and(ci_reminder())->toContain('GitHub Actions ended on '.CI_HANDOFF.' without check Required checks: check suite 5 ('.$conclusion.'). No failed job explains it')
            ->and(ci_reminder())->toContain('https://github.com/acme/orbit/commit/'.CI_HANDOFF.'/checks'),
        'infrastructure' => $waits()->and($fresh?->assistance_reason)->toBe(ci_assistance('CI did not run to completion on handoff commit '.CI_HANDOFF
            .': GitHub Actions cancelled check suite 5 (cancelled) before check Required checks ran. Check the runners.')),
        'unexplained' => $waits()->and($fresh?->assistance_reason)->toBe(ci_assistance('GitHub Actions completed every workflow on handoff commit '.CI_HANDOFF
            .' without check Required checks: check suite 5 ('.$conclusion.'). A renamed or removed job, or a workflow that no longer runs on pushes to task-*-handoff branches, causes this. '
            .'Fix the workflow or the Project merge check.')),
    };
    expect($fresh?->status)->toBe(TaskStatus::Running);
})->with([
    'no Actions suite yet' => [null, null, 'pending'],
    'an unfinished suite' => ['in_progress', null, 'pending'],
    'a cancelled suite' => ['completed', 'cancelled', 'infrastructure'],
    'a workflow GitHub could not start' => ['completed', 'startup_failure', 'implementer'],
    'a failed workflow' => ['completed', 'failure', 'implementer'],
    // Each of these ran to an end without the merge check, such as after a renamed job.
    'a passed workflow' => ['completed', 'success', 'unexplained'],
    'a neutral workflow' => ['completed', 'neutral', 'unexplained'],
    'a skipped workflow' => ['completed', 'skipped', 'unexplained'],
    'a timed-out workflow' => ['completed', 'timed_out', 'unexplained'],
    'a workflow that needs an action' => ['completed', 'action_required', 'unexplained'],
    'a stale workflow' => ['completed', 'stale', 'unexplained'],
    'a conclusion GitHub adds later' => ['completed', 'something_new', 'unexplained'],
]);

it('returns the failed jobs of a workflow that ended without the merge check, and lets that fault decide', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Gateway shards (3)', 'failure', CI_HANDOFF, 4)];
    $github->suites[CI_HANDOFF] = [ci_suite('completed', 'success', 5), ci_suite('completed', 'cancelled', 6), ci_suite('completed', 'failure', 7)];
    ci_handoff();

    ci_ticks();

    expect(TaskCheck::query()->sole()->output)->toStartWith('GitHub Actions ended on '.CI_HANDOFF.' without check Required checks: check suite 7 (failure).'
        ."\n\nCheck Gateway shards (3) failed (https://github.com/acme/orbit/actions/runs/9/job/4).");
});

it('fails the handoff when the commit does not hold the tree the check passed on, and pushes nothing', function (): void {
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

it('commits nothing and reads no CI for a Project without review and merge', function (?string $mergeCheck): void {
    $github = ci_github();
    [$group, $task] = tick_deliverables([], [], null);
    // Such a Project's CI may not run on handoff branches, so it keeps the task check alone.
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git', 'merge_check' => $mergeCheck]);

    ci_ticks();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->messages)->toBe([])
        ->and($github->reads)->toBe(0)
        ->and(TaskCheck::query()->sole()->commit_sha)->toBeNull();
})->with(['no merge check' => [null], 'a merge check without review and merge' => ['Required checks']]);

it('asks for assistance once when no GitHub Actions suite started ten minutes after the push', function (): void {
    $this->freezeSecond();
    $github = ci_github();
    [, $task] = ci_handoff();

    ci_ticks();
    $this->travel(TaskHandoffCi::NoSuiteSeconds - 1)->seconds();
    ci_ticks(1);
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(TaskCheck::query()->sole()->pushed_at)->not->toBeNull();

    $this->travel(2)->seconds();
    ci_ticks(3);
    expect($task->fresh()?->assistance_reason)->toBe(ci_assistance('GitHub Actions started no workflow on handoff commit '.CI_HANDOFF
        .' in the 10 minutes since Orbit pushed it to task-'.$task->parent_id.'-handoff. A ci.yml on the task branch without the task-*-handoff push trigger, '
        .'such as one from before that trigger existed, or a skipped workflow causes this. Merge main into the task branch.'))
        ->and(Activity::query()->where('subject_type', Task::class)->where('subject_id', $task->id)
            ->where('description', 'assistance requested')->count())->toBe(1)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Passed)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
        ->and($github->handoffs)->toBe([CI_HANDOFF]);
});

it('removes every directive that would make GitHub skip CI from the handoff commit message', function (): void {
    $github = ci_github();
    $github->runs[CI_HANDOFF] = [ci_run('Required checks', 'success', CI_HANDOFF)];
    [, $task] = ci_handoff("Done [skip ci] and [ci skip], [no ci], [Skip Actions], [actions skip].\n\nskip-checks: true");

    ci_ticks();

    expect($github->messages)->toBe(["Models\n\nDone  and , , , .\n\n"])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('asks for assistance on the fifth tick that cannot push the commit or read its checks', function (string $flag, string $reason): void {
    $github = ci_github();
    $github->{$flag} = true;
    [, $task] = ci_handoff();

    ci_ticks(5);
    expect($task->fresh()?->assistance_requested)->toBeFalse();

    ci_ticks(1);
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toContain($reason)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
})->with([
    'a failed push' => ['pushFails', 'The task branch could not be pushed.'],
    'an unreadable GitHub' => ['unreadable', 'Orbit could not read the checks of '.CI_HANDOFF.': GitHub is unavailable.'],
    'no checks permission' => ['noChecksPermission', 'Orbit could not read the checks of '.CI_HANDOFF.': the GitHub App has no checks: read permission.'],
]);

it('judges only the newest workflow suite when GitHub supersedes a handoff run', function (string $status, ?string $conclusion, bool $mergeCheck, string $outcome): void {
    ci_github();
    app()->bind(TaskPullRequestMerger::class, GitHubTaskPullRequestMerger::class);
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    $run = static fn (string $name, int $id, int $suite, ?string $result): array => [
        'name' => $name, 'id' => $id, 'check_suite' => ['id' => $suite], 'head_sha' => CI_HANDOFF,
        'app' => ['slug' => 'github-actions'], 'status' => $result === null ? 'in_progress' : 'completed', 'conclusion' => $result,
    ];
    $runs = [
        $run('Gateway shards (1)', 1, 5, 'cancelled'), $run('Required checks', 2, 5, 'failure'),
        $run('Gateway shards (1)', 3, 6, $conclusion),
        ...($mergeCheck ? [$run('Required checks', 4, 6, $conclusion)] : []),
    ];
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => static fn () => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn () => Http::response(['token' => 'ghs_read', 'permissions' => ['checks' => 'read', 'actions' => 'read']], 201),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => 2, 'check_suites' => [
            ['id' => 5, 'status' => 'completed', 'conclusion' => 'cancelled', 'app' => ['slug' => 'github-actions']],
            ['id' => 6, 'status' => $status, 'conclusion' => $conclusion, 'app' => ['slug' => 'github-actions']],
        ]]),
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => Http::response(['total_count' => count($runs), 'check_runs' => $runs]),
        'https://api.github.com/repos/acme/orbit/actions/runs*' => static fn () => Http::response(['total_count' => 2, 'workflow_runs' => [
            ['id' => 20, 'workflow_id' => 10, 'event' => 'push', 'head_branch' => 'task-7-handoff', 'head_sha' => CI_HANDOFF, 'check_suite_id' => 6],
            ['id' => 19, 'workflow_id' => 10, 'event' => 'push', 'head_branch' => 'task-7-handoff', 'head_sha' => CI_HANDOFF, 'check_suite_id' => 5],
        ]]),
    ]);
    [, $task] = ci_handoff();

    ci_ticks();

    $fresh = $task->fresh();
    $check = TaskCheck::query()->sole();
    expect($fresh?->communication_failures)->toBe(0);
    match ($outcome) {
        'pending' => expect($fresh?->status)->toBe(TaskStatus::Running)->and($fresh?->assistance_requested)->toBeFalse()
            ->and($check->status)->toBe(TaskCheckStatus::Passed)->and(app(AgentCommandDispatcher::class)->commands)->toBe([]),
        'passed' => expect($fresh?->status)->toBe(TaskStatus::Reviewing)->and($fresh?->assistance_requested)->toBeFalse(),
        'failed' => expect($check->failed_step)->toBe(TaskHandoffCi::FailedStep)->and(ci_reminder())->toContain('Gateway shards (1)'),
        'infrastructure' => expect($fresh?->assistance_requested)->toBeTrue()->and($fresh?->assistance_reason)->toContain('check suite 6 (cancelled)'),
    };
})->with([
    'newest suite has no merge-check job yet' => ['in_progress', null, false, 'pending'],
    'newest merge check is running' => ['in_progress', null, true, 'pending'],
    'newest merge check passed' => ['completed', 'success', true, 'passed'],
    'newest merge check failed' => ['completed', 'failure', true, 'failed'],
    'newest suite was cancelled too' => ['completed', 'cancelled', false, 'infrastructure'],
]);
