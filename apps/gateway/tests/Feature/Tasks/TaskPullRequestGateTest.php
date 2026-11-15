<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Actions\Tasks\StartTaskPullRequestStepAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskVmRemoved;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewRound;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

const GATE_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/** The Tasks extension on, the GitHub App stored, and a GitHub that serves one pull request and no check runs or reviews. */
function gateWorld(GitHubPullRequest $pull, bool $reviewAndMerge = true, bool $green = false, TaskReviewMode $review = TaskReviewMode::Manual, array|Throwable $reviews = [], array|Throwable $comments = []): Task
{
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull, $green, $reviews, $comments): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn($pull);
        $github->shouldReceive('checkRuns')->andReturn($green ? [new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: GATE_HEAD, appSlug: 'github-actions')] : []);
        $reviews instanceof Throwable ? $github->shouldReceive('reviews')->andThrow($reviews) : $github->shouldReceive('reviews')->andReturn($reviews);
        $comments instanceof Throwable ? $github->shouldReceive('reviewComments')->andThrow($comments) : $github->shouldReceive('reviewComments')->andReturn($comments);
    }));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => $reviewAndMerge]);
    // The Orbit Project is the proof Project: the only Project whose checkout the task VM base carries.
    config(['proof.project' => $project->id]);

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Gate', 'brief' => 'Gate it', 'status' => TaskGroupStatus::WaitingForReview,
        'orchestration' => TaskOrchestration::External, 'watched_pr_number' => 7, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/7', 'review_mode' => $review]);
}

function gatePull(bool $draft, GitHubPullRequestState $state = GitHubPullRequestState::Open): GitHubPullRequest
{
    return new GitHubPullRequest($state, true, 'clean', GATE_HEAD, 'main', draft: $draft, authorLogin: 'orbit-app[bot]');
}

it('looks only at Projects with review and merge enabled', function (): void {
    gateWorld(gatePull(true), reviewAndMerge: false);

    expect(app(RunTaskPullRequestGateAction::class)->execute())->toBe([]);
});

it('looks at no group whose group or pull request has ended', function (): void {
    $group = gateWorld(gatePull(true));
    $group->forceFill(['watched_pr_completion' => 'merged'])->save();

    expect(app(RunTaskPullRequestGateAction::class)->execute())->toBe([]);
});

it('skips a pull request that is no longer open', function (): void {
    $group = gateWorld(gatePull(false, GitHubPullRequestState::Closed));

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => null, 'result' => 'skipped', 'code' => 'tasks_pr.no_pull_request', 'operation' => null]]);
});

/** A passed handoff check of the group on the head, with the check operation that records it. */
function gateChecked(Task $group, string $head = GATE_HEAD): TaskCheck
{
    $subtask = $group->tasks()->create(['title' => 'S', 'brief' => 'B']);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('1', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('3', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => $head, 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => ['state' => 'passed', 'fault' => null, 'commit' => $head, 'branch' => TaskRemoteBranch::handoff($group), 'check_name' => 'Required checks']]]);

    return $check;
}

it('leaves a draft alone while no passed handoff check is on its head, as for a spec-only pull request', function (): void {
    $group = gateWorld(gatePull(true), green: true);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_head', 'operation' => null]])
        ->and(Operation::query()->count())->toBe(0);
});

it('leaves a draft alone when the passed handoff check is on another commit', function (): void {
    $group = gateWorld(gatePull(true), green: true);
    gateChecked($group, str_repeat('b', 40));

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_head']);
});

it('admits no ready step while the merge check has not passed on the head of a checked draft', function (): void {
    $group = gateWorld(gatePull(true));
    gateChecked($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed', 'operation' => null]]);
});

it('tries the ready step for a checked draft with a green merge check', function (): void {
    $group = gateWorld(gatePull(true), green: true);
    gateChecked($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'result' => 'admitted', 'code' => null]);
});

it('admits no merge step while the check has not passed or the reviewer has not approved', function (): void {
    $group = gateWorld(gatePull(false));

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'merge', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed', 'operation' => null]])
        ->and(Operation::query()->count())->toBe(0);
});

it('replays the step that the key of the same head already recorded and leaves the identity of the request alone', function (): void {
    $group = gateWorld(gatePull(true));
    gateChecked($group);
    $existing = Operation::query()->count();
    $recorded = Operation::query()->create(['kind' => OperationKinds::TasksPrReady, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'idempotency_key' => 'pr-gate:ready:'.$group->id.':'.GATE_HEAD, 'idempotency_fingerprint' => hash('sha256', OperationKinds::TasksPrReady.'|'.$group->id.'|'.GATE_HEAD),
        'input' => ['head_sha' => GATE_HEAD], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'replayed', 'code' => null, 'operation' => $recorded->id]])
        ->and(Operation::query()->count())->toBe($existing + 1)
        ->and(app(OperationIdempotency::class)->key)->toBeNull();
});

it('registers the gate check on the Gateway schedule every minute', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan task:pr-gate/');
});

function gateRound(Task $group, int $round, ?TaskReviewRoundVerdict $verdict, string $head = GATE_HEAD, ?string $pushed = null): TaskReviewRound
{
    return TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => $head, 'round' => $round, 'verdict' => $verdict, 'pushed_commit' => $pushed]);
}

it('waits for a round that runs and leaves the comment, the fix and the push to its one run', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    $round = gateRound($group, 1, null);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.round_running']);

    $round->update(['verdict' => TaskReviewRoundVerdict::Findings, 'pushed_commit' => str_repeat('c', 40)]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.fix_pushed'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Automatic)
        ->and(Operation::query()->count())->toBe(0);
});

it('tries the merge step once the round on the exact head passed', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Pass);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'merge', 'result' => 'admitted', 'code' => null]);
});

it('refuses the automatic merge when no round passed the head', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Findings);
    gateRound($group, 2, TaskReviewRoundVerdict::Pass, str_repeat('b', 40));

    expect(fn () => app(StartTaskPullRequestStepAction::class)->merge($group, GATE_HEAD, 'test', (string) Str::uuid()))
        ->toThrow(ResourceOperationException::class, 'No review round passed this head.');
});

it('falls back to manual mode for findings that no push followed, as after round 3, and runs the ready step again', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Findings, str_repeat('1', 40), str_repeat('2', 40));
    gateRound($group, 2, TaskReviewRoundVerdict::Findings, str_repeat('2', 40), str_repeat('3', 40));
    gateRound($group, 3, TaskReviewRoundVerdict::Findings);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual', 'result' => 'admitted', 'code' => null])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

it('falls back to manual mode when a round failed', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Failed);

    app(RunTaskPullRequestGateAction::class)->execute();

    expect($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

it('falls back to manual mode when a new head finds the three rounds used', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    foreach ([1, 2, 3] as $round) {
        gateRound($group, $round, TaskReviewRoundVerdict::Findings, str_repeat((string) $round, 40));
    }

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

/** What a prep run leaves: its completed spec subtask and its succeeded run. */
function gateSpecDone(Task $group): void
{
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed]);
    Operation::query()->create(['kind' => OperationKinds::TasksPrep, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'status' => OperationStatus::Succeeded, 'input' => ['command' => 'firewall:deny']]);
}

function gateReview(string $commit, GitHubReviewState $state = GitHubReviewState::Approved): GitHubReview
{
    return new GitHubReview(1, 1, 'nick', $state, $commit, new DateTimeImmutable('2026-11-06T10:00:00Z'), 'https://example.test', '');
}

it('leaves a draft alone when its group has a succeeded build', function (): void {
    $running = gateWorld(gatePull(true));
    gateSpecDone($running);
    Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $running->id, 'node_id' => null, 'status' => OperationStatus::Succeeded, 'input' => []]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'code' => 'tasks_pr.no_checked_head']);
});

it('never builds from a pull request that is out of draft', function (): void {
    $group = gateWorld(gatePull(false));
    gateSpecDone($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'merge']);
});

/** The spec a prep run leaves, and the task VM that it parked (or left in another state). */
function gatePrepped(Task $group, TaskVmState $state = TaskVmState::Parked): void
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('gate-host', '10.44.0.21');
    $guest = $node('gate-vm', '10.44.0.22');
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'gate-vm',
        'state' => $state, 'address' => '10.251.78.34', 'wireguard_ip' => '10.44.0.22', 'size' => TaskVmSize::Normal, 'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);

    gateSpecDone($group);
}

function gateOwnerReview(int $id, GitHubReviewState $state, string $head = GATE_HEAD, string $body = 'Fix the chart.', string $login = 'nick', int $minutes = 0): GitHubReview
{
    return new GitHubReview($id, 1, $login, $state, $head, new DateTimeImmutable('2026-11-06 10:00:00')->modify("+{$minutes} minutes"), 'https://github.com/hardimpactdev/orbit/pull/7#pullrequestreview-'.$id, $body);
}

it('starts no corrections for a group without a prep run', function (): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(true), reviews: [gateOwnerReview(31, GitHubReviewState::ChangesRequested)]);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_head', 'operation' => null]]);
});

it('starts no corrections for a group whose build started: its later change request is for the code', function (): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(true), reviews: [gateOwnerReview(31, GitHubReviewState::ChangesRequested)]);
    gatePrepped($group);
    Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null, 'status' => OperationStatus::Succeeded, 'input' => []]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'code' => 'tasks_pr.no_checked_head'])
        ->and(Operation::query()->where('kind', OperationKinds::TasksPrepFix)->count())->toBe(0);
});

it('records the refusal of the removed task VMs where it would start a review round or a build', function (string $case, string $step): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = match ($case) {
        'automatic round' => gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic),
        'approved spec' => gateWorld(gatePull(true), reviews: [gateReview(GATE_HEAD)]),
        // The corrections were the answer to a build that lacked the approval; the build is refused first, so no corrections start.
        'spec with a change request' => gateWorld(gatePull(true), reviews: [gateOwnerReview(31, GitHubReviewState::ChangesRequested)]),
    };
    match ($case) {
        'automatic round' => null,
        'approved spec' => gateSpecDone($group),
        'spec with a change request' => gatePrepped($group),
    };

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => $step, 'result' => 'skipped', 'code' => TaskVmRemoved::Code, 'operation' => null]])
        ->and(Operation::query()->whereIn('kind', [OperationKinds::TasksReviewRound, OperationKinds::TasksBuild, OperationKinds::TasksPrepFix])->count())->toBe(0);
})->with([
    'automatic round' => ['automatic round', 'review'],
    'approved spec' => ['approved spec', 'build'],
    'spec with a change request' => ['spec with a change request', 'build'],
]);
