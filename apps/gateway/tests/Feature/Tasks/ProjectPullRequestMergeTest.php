<?php

declare(strict_types=1);

use App\Actions\Projects\StartProjectPullRequestMergeAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

const OUTSIDE_HEAD = 'c1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/** The GitHub App stored and a GitHub that serves one pull request with the merge check green or red on the head. */
function outsideWorld(bool $draft = false, bool $green = true, string $head = OUTSIDE_HEAD, bool $reviewAndMerge = true): Project
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($draft, $green, $head): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn(new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', $head, 'main', draft: $draft, authorLogin: 'nckrtl'));
        $github->shouldReceive('checkRuns')->andReturn($green ? [new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: OUTSIDE_HEAD, appSlug: 'github-actions')] : []);
    }));

    return Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => $reviewAndMerge]);
}

function outsideMerge(Project $project, int $number = 2981, string $head = OUTSIDE_HEAD): Operation
{
    return app(StartProjectPullRequestMergeAction::class)->execute($project, $number, $head, 'test', (string) Str::uuid());
}

it('refuses a Project that does not take part in review and merge', function (): void {
    $project = outsideWorld(reviewAndMerge: false);

    expect(fn () => outsideMerge($project))->toThrow(ResourceOperationException::class, 'review and merge');
});

it('refuses a pull request that a Task group watches, and merges it only through tasks:pr-merge', function (): void {
    $project = outsideWorld();
    Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'B', 'status' => TaskGroupStatus::WaitingForReview, 'orchestration' => TaskOrchestration::External,
        'watched_pr_number' => 2981, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/2981']);

    expect(fn () => outsideMerge($project))->toThrow(ResourceOperationException::class, 'tasks:pr-merge');
});

it('refuses a draft, a moved head and a merge check that did not pass', function (array $world, string $code): void {
    $project = outsideWorld(...$world);

    try {
        outsideMerge($project);
        $thrown = null;
    } catch (ResourceOperationException $exception) {
        $thrown = $exception->errorCode;
    }

    expect($thrown)->toBe($code)
        ->and(Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->count())->toBe(0);
})->with([
    'a draft' => [['draft' => true], 'tasks_pr.draft'],
    'a moved head' => [['head' => str_repeat('9', 40)], 'tasks_pr.head_moved'],
    'a red check' => [['green' => false], 'tasks_pr.checks_not_passed'],
]);

it('passes every gate for an open pull request with a green merge check on the exact head and reaches the start of the run', function (): void {
    $project = outsideWorld();

    // The test world has no Swamp to start; the refusal shows that admission passed every gate and tried to start the run.
    expect(fn () => outsideMerge($project))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->first();
    expect($operation?->target_type)->toBe('project')
        ->and($operation?->target_id)->toBe($project->id)
        ->and($operation?->input)->toMatchArray(['project_id' => $project->id, 'repository' => 'hardimpactdev/orbit', 'pr_number' => 2981, 'head_sha' => OUTSIDE_HEAD])
        ->and($operation?->input['execution_secrets']['write_token']['key'])->toMatch('/\Apr-merge-write-[a-f0-9-]{36}\z/');
});

it('registers the kind, the route and the workflow of the merge outside every task group', function (): void {
    $kinds = require app_path('Domain/Projects/direct-operations.php');
    $route = app('router')->getRoutes()->getByName('project:pr-merge');

    expect($kinds[OperationKinds::ProjectPrMerge]['workflow'])->toBe('operation-task-pr-merge')
        ->and($kinds[OperationKinds::ProjectPrMerge]['execution_copies'])->toBe([['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-merge-write-[a-f0-9-]{36}$']])
        ->and($route?->uri())->toBe('api/v1/projects/{project}/pr-merge')
        ->and($route?->methods())->toContain('POST');
});
