<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

const GATE_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/** The Tasks extension on, the GitHub App stored, and a GitHub that serves one pull request and no check runs or reviews. */
function gateWorld(GitHubPullRequest $pull, bool $reviewAndMerge = true): Task
{
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn($pull);
        $github->shouldReceive('checkRuns')->andReturn([]);
        $github->shouldReceive('reviews')->andReturn([]);
    }));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => $reviewAndMerge]);

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Gate', 'brief' => 'Gate it', 'status' => TaskGroupStatus::WaitingForReview,
        'orchestration' => TaskOrchestration::External, 'watched_pr_number' => 7, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/7']);
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
