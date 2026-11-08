<?php

declare(strict_types=1);

use App\Actions\Projects\StartProjectPullRequestMergeAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestMergeProjection;
use App\Domain\Tasks\TaskPullRequestTarget;
use App\Http\Requests\Projects\ProjectPullRequestMergeRequest;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

const OUTSIDE_HEAD = 'c1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/** The GitHub App stored and a GitHub that serves one pull request with the merge check green or red on the head. */
function outsideWorld(bool $draft = false, bool $green = true, string $head = OUTSIDE_HEAD, bool $reviewAndMerge = true, GitHubPullRequestState $state = GitHubPullRequestState::Open, string $base = 'main', bool $fork = false): Project
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($draft, $green, $head, $state, $base, $fork): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn(new GitHubPullRequest($state, true, 'clean', $head, $base, draft: $draft, authorLogin: 'nckrtl', fromFork: $fork));
        $github->shouldReceive('checkRuns')->andReturn($green ? [new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: OUTSIDE_HEAD, appSlug: 'github-actions')] : []);
    }));

    return Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => $reviewAndMerge]);
}

function outsideMerge(Project $project, int $number = 2981, string $head = OUTSIDE_HEAD): Operation
{
    return app(StartProjectPullRequestMergeAction::class)->execute($project, $number, $head, 'test', (string) Str::uuid());
}

/** The error code of an admission that refused, or null when it returned an operation. */
function outsideRefusal(Project $project): ?string
{
    try {
        outsideMerge($project);

        return null;
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }
}

function outsideGroup(Project $project, TaskGroupStatus $status): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'B', 'status' => $status, 'orchestration' => TaskOrchestration::External,
        'watched_pr_number' => 2981, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/2981']);
}

// The test world has no Swamp to start. The refusal `swamp.start_unavailable` shows that admission passed every gate and tried to start the run.
const OUTSIDE_STARTED = 'swamp.start_unavailable';

it('refuses a Project that does not take part in review and merge', function (): void {
    expect(outsideRefusal(outsideWorld(reviewAndMerge: false)))->toBe('project.review_and_merge_disabled');
});

it('refuses a pull request that an open Task group watches, and merges it only through task:pr-merge', function (): void {
    $project = outsideWorld();
    outsideGroup($project, TaskGroupStatus::WaitingForReview);

    expect(outsideRefusal($project))->toBe('project.pr_in_task_group');
});

it('does not let a group that ended hold its pull request', function (TaskGroupStatus $status): void {
    $project = outsideWorld();
    outsideGroup($project, $status);

    expect(outsideRefusal($project))->toBe(OUTSIDE_STARTED);
})->with([TaskGroupStatus::Cancelled, TaskGroupStatus::Failed, TaskGroupStatus::Completed]);

it('refuses a draft, a closed pull request, a moved head and a merge check that did not pass', function (array $world, string $code): void {
    $project = outsideWorld(...$world);

    expect(outsideRefusal($project))->toBe($code)
        ->and(Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->count())->toBe(0);
})->with([
    'a draft' => [['draft' => true], 'tasks_pr.draft'],
    'a closed pull request' => [['state' => GitHubPullRequestState::Closed], 'tasks_pr.no_pull_request'],
    'a moved head' => [['head' => str_repeat('9', 40)], 'tasks_pr.head_moved'],
    'a red check' => [['green' => false], 'tasks_pr.checks_not_passed'],
    'another base branch' => [['base' => 'release'], 'tasks_pr.wrong_base'],
    'a fork' => [['fork' => true], 'tasks_pr.from_fork'],
]);

it('passes every gate for an open pull request with a green merge check on the exact head and records the intent with a token key name only', function (): void {
    $project = outsideWorld();

    expect(outsideRefusal($project))->toBe(OUTSIDE_STARTED);

    $operation = Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->sole();
    expect($operation->target_type)->toBe('project')
        ->and($operation->target_id)->toBe($project->id)
        ->and($operation->input)->toMatchArray(['project_id' => $project->id, 'repository' => 'hardimpactdev/orbit', 'pr_number' => 2981, 'head_sha' => OUTSIDE_HEAD])
        ->and(array_keys($operation->input['execution_secrets']))->toBe(['write_token'])
        ->and($operation->input['execution_secrets']['write_token']['key'])->toMatch('/\Apr-merge-write-[a-f0-9-]{36}\z/');
});

it('refuses a second merge of the Project while one runs', function (): void {
    $project = outsideWorld();
    Operation::query()->create(['kind' => OperationKinds::ProjectPrMerge, 'target_type' => 'project', 'target_id' => $project->id, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'lock_key' => OperationStore::lockKey('project', $project->id), 'input' => [], 'sequence' => 1, 'attempts' => 1]);

    expect(outsideRefusal($project))->toBe('operation.target_busy');
});

it('replays the merge that the same key recorded, and refuses the same key with another request', function (): void {
    $project = outsideWorld();
    $idempotency = app(OperationIdempotency::class);
    $admit = fn (string $fingerprint) => $idempotency->under(OperationKinds::ProjectPrMerge, 'merge-2981', $fingerprint, fn (): Operation => outsideMerge($project));

    expect(fn () => $admit('request-a'))->toThrow(ResourceOperationException::class);
    $recorded = Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->sole();

    expect($admit('request-a')->id)->toBe($recorded->id)
        ->and(fn () => $admit('request-b'))->toThrow(ResourceOperationException::class, 'Idempotency-Key')
        ->and(Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->count())->toBe(1);
});

it('validates the request to a pull request number and a full head SHA', function (array $body, bool $valid): void {
    expect(Validator::make($body, (new ProjectPullRequestMergeRequest)->rules())->passes())->toBe($valid);
})->with([
    'both' => [['pull_request' => 2981, 'head_sha' => OUTSIDE_HEAD], true],
    'a short SHA' => [['pull_request' => 2981, 'head_sha' => substr(OUTSIDE_HEAD, 0, 39)], false],
    'an upper-case SHA' => [['pull_request' => 2981, 'head_sha' => strtoupper(OUTSIDE_HEAD)], false],
    'pull request 0' => [['pull_request' => 0, 'head_sha' => OUTSIDE_HEAD], false],
    'no pull request' => [['head_sha' => OUTSIDE_HEAD], false],
]);

it('registers the kind with the workflow of the task merge, its projection and its token key, and routes it on a Project', function (): void {
    $kinds = app(DirectOperation::class)->definitions();
    $route = app('router')->getRoutes()->getByName('project:pr-merge');

    expect($kinds)->toHaveKey(OperationKinds::ProjectPrMerge)
        ->and($kinds[OperationKinds::ProjectPrMerge]['workflow'])->toBe('operation-task-pr-merge')
        ->and($kinds[OperationKinds::ProjectPrMerge]['projection'])->toBe(TaskPullRequestMergeProjection::class)
        ->and($kinds[OperationKinds::ProjectPrMerge]['execution_copies'])->toBe([['vault' => 'orbit-control-inputs', 'key_pattern' => '^pr-merge-write-[a-f0-9-]{36}$']])
        ->and($route?->uri())->toBe('api/v1/projects/{project}/pr-merge')
        ->and($route?->methods())->toContain('POST');
});

it('keeps the group in the intent of a task merge, which the workflow schema no longer requires', function (): void {
    $group = (new Task)->forceFill(['id' => 5, 'project_id' => 7]);
    $pull = new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', OUTSIDE_HEAD, 'main', draft: false, authorLogin: 'nckrtl');
    $target = new TaskPullRequestTarget(GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit.git'), 57, $pull);

    expect($target->intent($group))->toBe(['project_id' => 7, 'group_id' => 5, 'repository' => 'hardimpactdev/orbit', 'pr_number' => 57]);
});

it('reads a pull request from another repository, and one whose fork was deleted, as a fork', function (?string $head, bool $fork): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/pulls/2981' => Http::response([
        'state' => 'open', 'merged' => false, 'mergeable' => true, 'mergeable_state' => 'clean', 'draft' => false, 'user' => ['login' => 'someone'],
        'head' => ['sha' => OUTSIDE_HEAD, 'repo' => $head === null ? null : ['full_name' => $head]], 'base' => ['ref' => 'main', 'repo' => ['full_name' => 'hardimpactdev/orbit']],
    ])]);

    $pull = app(HttpGitHubApi::class)->pullRequest('token', GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit.git'), 2981);

    expect($pull->fromFork)->toBe($fork);
})->with([
    'a branch of the repository' => ['hardimpactdev/orbit', false],
    'another repository' => ['someone/orbit', true],
    'a deleted fork' => [null, true],
]);
