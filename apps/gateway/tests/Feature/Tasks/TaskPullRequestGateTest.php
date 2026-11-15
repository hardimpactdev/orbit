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
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

/** A review round as the removed review round step recorded it; the table stays as history (docs/reference/old-paths.md). */
function gateOldRound(Task $group, int $round, string $verdict, string $head = GATE_HEAD): void
{
    DB::table('task_review_rounds')->insert(['task_group_id' => $group->id, 'head_sha' => $head, 'round' => $round, 'verdict' => $verdict, 'created_at' => now(), 'updated_at' => now()]);
}

/** What a prep run leaves: its completed spec subtask and its succeeded run. */
function gateSpecDone(Task $group): void
{
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed]);
    Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'status' => OperationStatus::Succeeded, 'input' => ['command' => 'firewall:deny']]);
}

/** The spec a prep run leaves, and the task VM that it parked. */
function gatePrepped(Task $group): void
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('gate-host', '10.44.0.21');
    $guest = $node('gate-vm', '10.44.0.22');
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'gate-vm',
        'state' => TaskVmState::Parked, 'address' => '10.251.78.34', 'wireguard_ip' => '10.44.0.22', 'size' => TaskVmSize::Normal, 'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);

    gateSpecDone($group);
}

function gateOwnerReview(int $id, GitHubReviewState $state, string $head = GATE_HEAD): GitHubReview
{
    return new GitHubReview($id, 1, 'nick', $state, $head, new DateTimeImmutable('2026-11-06T10:00:00Z'), 'https://github.com/hardimpactdev/orbit/pull/7#pullrequestreview-'.$id, 'Fix the chart.');
}

// The review rounds, the build and the corrections of a prep group are removed with the Pi task loop (docs/reference/old-paths.md).
it('switches a group in automatic mode to manual mode and runs the ready step again, whatever old rounds it has', function (array $rounds): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    // Each old round is on its own head; the last one is on the head of the pull request.
    foreach ($rounds as $number => $verdict) {
        gateOldRound($group, $number + 1, $verdict, $number === count($rounds) - 1 ? GATE_HEAD : str_repeat((string) ($number + 1), 40));
    }

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual', 'result' => 'admitted', 'code' => null])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual)
        ->and(Operation::query()->where('kind', 'tasks.review_round')->count())->toBe(0);
})->with([
    'no round' => [[]],
    'a passed round on the head' => [['pass']],
    'three rounds with findings' => [['findings', 'findings', 'findings']],
]);

it('keeps automatic mode while the ready step is refused, so the next tick asks the reviewer instead of waiting at the merge', function (): void {
    $group = gateWorld(gatePull(false), green: false, review: TaskReviewMode::Automatic);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Automatic)
        ->and(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual']);
});

it('merges a group that was in automatic mode only with the reviewer\'s approval of the head, not with an old passed round', function (): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateOldRound($group, 1, 'pass');

    expect(fn () => app(StartTaskPullRequestStepAction::class)->merge($group, GATE_HEAD, 'test', (string) Str::uuid()))
        ->toThrow(ResourceOperationException::class, 'The reviewer has not approved this head.');
});

it('leaves the draft of an old prep group alone: no build and no corrections, whatever the owner\'s review', function (GitHubReviewState $state): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(true), reviews: [gateOwnerReview(31, $state)]);
    gatePrepped($group);
    $before = Operation::query()->count();

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_head', 'operation' => null]])
        ->and(Operation::query()->count())->toBe($before);
})->with([
    'approved spec' => [GitHubReviewState::Approved],
    'spec with a change request' => [GitHubReviewState::ChangesRequested],
]);

it('merges the out-of-draft pull request of an old prep group as any other: it never builds', function (): void {
    $group = gateWorld(gatePull(false));
    gateSpecDone($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'merge']);
});
