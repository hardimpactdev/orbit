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
use App\Domain\Tasks\TaskHandoffPullRequest;
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

        /** @var array<string, list<GitHubCheckSuite>> */
        public array $suites = [];

        /** @var list<bool> */
        public array $allowEmpty = [];

        /** @var list<string> */
        public array $messages = [];

        /** @var list<string> */
        public array $handoffs = [];

        public int $reads = 0;

        public bool $conflicts = false;

        /** Whether the handoff pull request is open with the commit as its head. */
        public bool $current = true;

        public bool $pullRequestUnreadable = false;

        public bool $republishFails = false;

        /** @var list<bool> the `$again` of each publish */
        public array $again = [];

        public bool $pending = false;

        public bool $unreadable = false;

        public bool $suitesUnreadable = false;

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

        public function checkSuites(Task $group, string $sha): ?array
        {
            if ($this->suitesUnreadable) {
                throw new TaskPullRequestException('Orbit could not read the checks of '.$sha.': GitHub is unavailable.');
            }

            return $this->suites[$sha] ?? [];
        }

        public function handoffPullRequest(Task $group, string $sha, int $number): TaskHandoffPullRequest
        {
            if ($this->pullRequestUnreadable) {
                throw new TaskPullRequestException('Orbit could not read pull request #'.$number.': GitHub is unavailable.');
            }

            return new TaskHandoffPullRequest($this->current, $this->current && $this->conflicts ? 'main' : null);
        }

        public function publishHandoff(Task $group, string $commit, bool $again = false): int
        {
            if ($this->pending) {
                throw TaskPullRequestException::pending('Orbit is pushing commit '.$commit.'.');
            }
            if ($again && $this->republishFails) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
            $this->handoffs[] = $commit;
            $this->again[] = $again;

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
        ->and($task->fresh()?->assistance_reason)->toBe('CI did not run to completion on handoff commit '.CI_HANDOFF.': Gateway shards (2) ('.$conclusion.'). Re-run it, then post a resolution so the implementer hands off again.')
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

it('gives every handoff its own commit, also when nothing changed, and publishes and tests it', function (): void {
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
    // Another App's run, such as Mintlify's, never decides whether GitHub Actions ran.
    $github->runs[CI_HANDOFF] = [new GitHubCheckRun('Mintlify Deployment', 'success', null, 9, null, 'completed', CI_HANDOFF, 'mintlify')];
    $github->suites[CI_HANDOFF] = [new GitHubCheckSuite(8, 'completed', 'success', 'mintlify'),
        ...($status === null ? [] : [new GitHubCheckSuite(5, $status, $conclusion, RequiredCheckState::CheckApp)])];
    [, $task] = ci_handoff();

    ci_ticks();

    $check = TaskCheck::query()->sole();
    $fresh = $task->fresh();
    $waits = static fn () => expect($check->status)->toBe(TaskCheckStatus::Passed)->and(app(AgentCommandDispatcher::class)->commands)->toBe([]);
    match ($outcome) {
        'pending' => $waits()->and($fresh?->assistance_requested)->toBeFalse(),
        'implementer' => expect($check->failed_step)->toBe(TaskHandoffCi::FailedStep)
            ->and(ci_reminder())->toContain('GitHub Actions ended on '.CI_HANDOFF.' before it ran check Required checks: check suite 5 ('.$conclusion.').')
            ->and(ci_reminder())->toContain('https://github.com/acme/orbit/commit/'.CI_HANDOFF.'/checks'),
        'infrastructure' => $waits()->and($fresh?->assistance_reason)->toBe('CI did not run to completion on handoff commit '.CI_HANDOFF
            .': GitHub Actions cancelled check suite 5 (cancelled) before check Required checks ran. Re-run it, then post a resolution so the implementer hands off again.'),
        'unexplained' => $waits()->and($fresh?->assistance_reason)->toBe('GitHub Actions completed every workflow on handoff commit '.CI_HANDOFF
            .' without check Required checks: check suite 5 ('.$conclusion.'). A renamed or removed job, or a narrower workflow trigger, causes this. '
            .'Fix the workflow or the Project merge check, then post a resolution so the implementer hands off again.'),
    };
    expect($fresh?->status)->toBe(TaskStatus::Running);
})->with([
    'no Actions suite yet' => [null, null, 'pending'],
    'an unfinished suite' => ['in_progress', null, 'pending'],
    'a cancelled suite' => ['completed', 'cancelled', 'infrastructure'],
    'a workflow GitHub could not start' => ['completed', 'startup_failure', 'implementer'],
    'a failed workflow' => ['completed', 'failure', 'implementer'],
    // Each of these ran to an end without the merge check, such as after a renamed job or a narrower trigger.
    'a passed workflow' => ['completed', 'success', 'unexplained'],
    'a neutral workflow' => ['completed', 'neutral', 'unexplained'],
    'a skipped workflow' => ['completed', 'skipped', 'unexplained'],
    'a timed-out workflow' => ['completed', 'timed_out', 'unexplained'],
    'a workflow that needs an action' => ['completed', 'action_required', 'unexplained'],
    'a stale workflow' => ['completed', 'stale', 'unexplained'],
    'a conclusion GitHub adds later' => ['completed', 'something_new', 'unexplained'],
]);

it('lets a fault of the change decide over the other completed suites', function (): void {
    $github = ci_github();
    $github->suites[CI_HANDOFF] = [new GitHubCheckSuite(5, 'completed', 'success', RequiredCheckState::CheckApp),
        new GitHubCheckSuite(6, 'completed', 'cancelled', RequiredCheckState::CheckApp), new GitHubCheckSuite(7, 'completed', 'failure', RequiredCheckState::CheckApp)];
    ci_handoff();

    ci_ticks();

    expect(TaskCheck::query()->sole()->output)->toContain('before it ran check Required checks: check suite 7 (failure).');
});

it('publishes the commit again when its pull request is closed or no longer has it as head', function (): void {
    $github = ci_github();
    $github->current = false;
    [, $task] = ci_handoff();

    ci_ticks();

    expect($github->handoffs)->toBe([CI_HANDOFF, CI_HANDOFF])
        ->and($github->again)->toBe([false, true])
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Passed)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);

    $github->current = true;
    $github->runs[CI_HANDOFF] = [ci_run('Required checks', 'success', CI_HANDOFF)];
    ci_ticks(1);
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($github->handoffs)->toHaveCount(2);
});

it('asks the implementer to merge the base when the conflict keeps the merge check from running', function (GitHubCheckSuite $suite): void {
    $github = ci_github();
    $github->conflicts = true;
    $github->runs[CI_HANDOFF] = [new GitHubCheckRun('Mintlify Deployment', 'success', null, 9, null, 'completed', CI_HANDOFF, 'mintlify')];
    $github->suites[CI_HANDOFF] = [$suite];
    ci_handoff();

    ci_ticks();

    expect(TaskCheck::query()->sole()->failed_step)->toBe(TaskHandoffCi::FailedStep)
        ->and(ci_reminder())->toContain('GitHub reports that the task branch conflicts with main, so CI cannot run on '.CI_HANDOFF.'.')
        ->and(ci_reminder())->toContain('Merge origin/main into the task branch');
})->with([
    // Mintlify still reports on the conflicting commit.
    'another App only' => [new GitHubCheckSuite(8, 'completed', 'success', 'mintlify')],
    // A workflow that runs on pushes still runs on the conflicting commit.
    'a running push workflow' => [new GitHubCheckSuite(5, 'in_progress', null, RequiredCheckState::CheckApp)],
]);

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

it('asks for assistance when the check suites stay unreadable while the merge check has no run', function (): void {
    $github = ci_github();
    $github->suitesUnreadable = true;
    [, $task] = ci_handoff();

    ci_ticks(6);

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toContain('Orbit could not read the checks of '.CI_HANDOFF);
});

it('asks for assistance when the handoff pull request stays unreadable while the merge check has no run', function (): void {
    $github = ci_github();
    $github->pullRequestUnreadable = true;
    [, $task] = ci_handoff();

    ci_ticks(6);

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toContain('Orbit could not read pull request #7');
});

it('asks for assistance when the commit cannot be published again', function (): void {
    $github = ci_github();
    $github->current = false;
    $github->republishFails = true;
    [, $task] = ci_handoff();

    ci_ticks(6);

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toContain('The task branch could not be pushed.');
});
