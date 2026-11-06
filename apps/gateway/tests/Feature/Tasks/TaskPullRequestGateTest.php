<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Actions\Tasks\StartTaskPullRequestStepAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewRound;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

const GATE_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/** The Tasks extension on, the GitHub App stored, and a GitHub that serves one pull request and no check runs or reviews. */
function gateWorld(GitHubPullRequest $pull, bool $reviewAndMerge = true, bool $green = false, TaskReviewMode $review = TaskReviewMode::Manual): Task
{
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull, $green): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn($pull);
        $github->shouldReceive('scopedRepositoryToken')->andThrow(GitHubApiException::unavailable());
        $github->shouldReceive('checkRuns')->andReturn($green ? [new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: GATE_HEAD, appSlug: 'github-actions')] : []);
        $github->shouldReceive('reviews')->andReturn([]);
    }));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => $reviewAndMerge]);

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

it('admits no ready step while the merge check has not passed on the head of a draft', function (): void {
    $group = gateWorld(gatePull(true));

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed', 'operation' => null]])
        ->and(Operation::query()->count())->toBe(0);
});

it('admits no merge step while the check has not passed or the reviewer has not approved', function (): void {
    $group = gateWorld(gatePull(false));

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'merge', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed', 'operation' => null]])
        ->and(Operation::query()->count())->toBe(0);
});

it('replays the step that the key of the same head already recorded and leaves the identity of the request alone', function (): void {
    $group = gateWorld(gatePull(true));
    $recorded = Operation::query()->create(['kind' => OperationKinds::TasksPrReady, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'idempotency_key' => 'pr-gate:ready:'.$group->id.':'.GATE_HEAD, 'idempotency_fingerprint' => hash('sha256', OperationKinds::TasksPrReady.'|'.$group->id.'|'.GATE_HEAD),
        'input' => ['head_sha' => GATE_HEAD], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'replayed', 'code' => null, 'operation' => $recorded->id]])
        ->and(Operation::query()->count())->toBe(1)
        ->and(app(OperationIdempotency::class)->key)->toBeNull();
});

it('registers the gate check on the Gateway schedule every minute', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan tasks:pr-gate/');
});

/** The comment of the round on the head already ended, as the key of the head records it. */
function gateCommented(Task $group, string $head = GATE_HEAD): void
{
    Operation::query()->create(['kind' => OperationKinds::TasksPrComment, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'idempotency_key' => 'pr-gate:comment:'.$group->id.':'.$head, 'idempotency_fingerprint' => hash('sha256', OperationKinds::TasksPrComment.'|'.$group->id.'|'.$head),
        'input' => ['head_sha' => $head], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);
}

function gateRound(Task $group, int $round, ?TaskReviewRoundVerdict $verdict, string $head = GATE_HEAD): TaskReviewRound
{
    return TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => $head, 'round' => $round, 'verdict' => $verdict]);
}

it('tries a review round on a head out of draft in automatic mode, and the round needs a passed handoff candidate', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_candidate', 'operation' => null]]);
});

it('admits no review round while the merge check has not passed on the head', function (): void {
    $group = gateWorld(gatePull(false), review: TaskReviewMode::Automatic);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['group' => $group->id, 'step' => 'review', 'code' => 'tasks_pr.checks_not_passed']);
});

it('waits for a round that runs and for findings that are not the third', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    $round = gateRound($group, 1, null);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.round_running']);

    $round->update(['verdict' => TaskReviewRoundVerdict::Findings]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'comment', 'result' => 'skipped', 'code' => 'tasks_pr.github_unreadable']);

    gateCommented($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.round_findings'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Automatic);
});

it('tries the merge step once the round on the exact head passed', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Pass);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'comment', 'result' => 'skipped', 'code' => 'tasks_pr.github_unreadable']);

    gateCommented($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'merge', 'result' => 'skipped', 'code' => 'tasks_pr.github_unreadable']);
});

it('refuses the automatic merge when no round passed the head', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Findings);
    gateRound($group, 2, TaskReviewRoundVerdict::Pass, str_repeat('b', 40));

    expect(fn () => app(StartTaskPullRequestStepAction::class)->merge($group, GATE_HEAD, 'test', (string) Str::uuid()))
        ->toThrow(ResourceOperationException::class, 'No review round passed this head.');
});

it('falls back to manual mode after round 3 with findings and runs the ready step again', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Findings, str_repeat('1', 40));
    gateRound($group, 2, TaskReviewRoundVerdict::Findings, str_repeat('2', 40));
    gateRound($group, 3, TaskReviewRoundVerdict::Findings);
    gateCommented($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual', 'code' => 'tasks_pr.github_unreadable'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

it('falls back to manual mode when a round failed', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Failed);
    gateCommented($group);

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
