<?php

declare(strict_types=1);

use App\Actions\Projects\StartProjectPullRequestMergeAction;
use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Actions\Tasks\StartTaskPullRequestStepAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DependencyBatchMergeGuard;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequest;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

const NO_MERGE_HEAD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    app(TaskExtensionState::class)->enable();
});

/** A GitHub that would let any merge pass: an open, green pull request out of draft. With `$read` false, any read fails the test. */
function noMergeGitHub(bool $read = true, ?string $headRef = null): void
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'example', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($read, $headRef): void {
        if (! $read) {
            $github->shouldNotReceive('pullRequest', 'reviews', 'checkRuns', 'repositoryReviewsToken', 'repositoryChecksToken');

            return;
        }
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn(new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', NO_MERGE_HEAD, 'main', draft: false, authorLogin: 'someone', fromFork: false, headRef: $headRef));
        $github->shouldReceive('checkRuns')->andReturn([new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: NO_MERGE_HEAD, appSlug: 'github-actions')]);
    }));
}

/** A task copy group of a review-and-merge Project whose publish opened pull request 7. */
function noMergeWorld(): TaskDoneFixture
{
    $world = TaskDoneFixture::make();
    $world->group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks', 'source_access' => 'github_app']);
    TaskDoneFixture::copyPublish($world->group, 7);
    $world->group->update(['pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_number' => 7, 'watched_pr_state' => 'open']);

    return $world;
}

function noMergeCode(Closure $call): ?string
{
    try {
        $call();

        return null;
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }
}

it('refuses task:pr-merge for a task copy group before any GitHub read', function (): void {
    $world = noMergeWorld();
    noMergeGitHub(read: false);

    expect(noMergeCode(fn () => app(TaskPullRequestEvidence::class)->merge($world->group->refresh(), NO_MERGE_HEAD)))->toBe(TaskPullRequest::Code)
        ->and(noMergeCode(fn () => app(StartTaskPullRequestStepAction::class)->merge($world->group->refresh(), NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::Code)
        ->and(Operation::query()->where('kind', OperationKinds::TasksPrMerge)->count())->toBe(0);
});

it('refuses task:pr-merge for a group that published from a task copy that is gone', function (): void {
    $world = noMergeWorld();
    $world->group->update(['taskable_type' => null, 'taskable_id' => null]);
    noMergeGitHub(read: false);

    expect(noMergeCode(fn () => app(TaskPullRequestEvidence::class)->merge($world->group->refresh(), NO_MERGE_HEAD)))->toBe(TaskPullRequest::Code);
});

it('refuses project:pr-merge for a task copy\'s pull request, whether its group is open or ended', function (TaskGroupStatus $status): void {
    $world = noMergeWorld();
    $world->group->update(['status' => $status, 'taskable_type' => null, 'taskable_id' => null]);
    noMergeGitHub();

    expect(noMergeCode(fn () => app(StartProjectPullRequestMergeAction::class)->execute($world->group->project, 7, NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::Code)
        ->and(Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->count())->toBe(0);
})->with(['open' => [TaskGroupStatus::Running], 'completed' => [TaskGroupStatus::Completed], 'cancelled' => [TaskGroupStatus::Cancelled]]);

it('refuses project:pr-merge for a pull request on the task branch of a copy group whose publish ended before it recorded the number', function (): void {
    $world = noMergeWorld();
    Operation::query()->where('kind', OperationKinds::TasksPublish)->update(['status' => 'interrupted', 'result' => null]);
    $world->group->update(['status' => TaskGroupStatus::Cancelled, 'taskable_type' => null, 'taskable_id' => null, 'watched_pr_number' => null, 'watched_pr_url' => null]);
    noMergeGitHub(headRef: 'task-'.$world->group->id);

    expect(TaskPullRequest::published($world->group->project, 7))->toBeFalse()
        ->and(noMergeCode(fn () => app(StartProjectPullRequestMergeAction::class)->execute($world->group->project, 7, NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::Code);
});

it('still merges the task branch pull request of a group that never had a task copy', function (): void {
    $world = noMergeWorld();
    $vm = Task::query()->create(['project_id' => $world->group->project_id, 'title' => 'VM group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Completed]);
    noMergeGitHub(headRef: 'task-'.$vm->id);

    $operation = app(StartProjectPullRequestMergeAction::class)->execute($world->group->project, 8, NO_MERGE_HEAD, 'test', (string) Str::uuid());

    expect($operation->kind)->toBe(OperationKinds::ProjectPrMerge);
});

it('still merges another pull request of the same Project through project:pr-merge', function (): void {
    $world = noMergeWorld();
    noMergeGitHub();

    $operation = app(StartProjectPullRequestMergeAction::class)->execute($world->group->project, 8, NO_MERGE_HEAD, 'test', (string) Str::uuid());

    expect($operation->kind)->toBe(OperationKinds::ProjectPrMerge);
});

/** A second review-and-merge Project that names the same repository as the copy's Project, as Project 46 does once Project 78 moves. */
function noMergeOtherProject(TaskDoneFixture $world): Project
{
    return Project::query()->create(['name' => 'Old Orbit', 'slug' => 'old-orbit', // Another spelling of the same repository: GitHub names are case-insensitive.
        'repository_url' => str_replace('github.com/example/', 'github.com/Example/', $world->group->project->repository_url),
        'default_branch' => 'main', 'review_and_merge' => true, 'merge_check' => 'Required checks', 'source_access' => 'github_app']);
}

it('refuses project:pr-merge of another Project on the same repository for a task copy\'s pull request', function (): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    noMergeGitHub();

    expect(noMergeCode(fn () => app(StartProjectPullRequestMergeAction::class)->execute($other, 7, NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::Code)
        ->and(Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->count())->toBe(0);
});

it('refuses project:pr-merge of another Project on the same repository for the task branch of a copy group whose publish recorded no number', function (): void {
    $world = noMergeWorld();
    Operation::query()->where('kind', OperationKinds::TasksPublish)->update(['status' => 'interrupted', 'result' => null]);
    $world->group->update(['status' => TaskGroupStatus::Cancelled, 'taskable_type' => null, 'taskable_id' => null, 'watched_pr_number' => null, 'watched_pr_url' => null]);
    $other = noMergeOtherProject($world);
    noMergeGitHub(headRef: 'task-'.$world->group->id);

    expect(noMergeCode(fn () => app(StartProjectPullRequestMergeAction::class)->execute($other, 7, NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::Code);
});

it('refuses project:pr-merge of a pull request that a group of another Project on the same repository published, also without a task copy', function (?string $headRef, int $number): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    $vm = Task::query()->create(['project_id' => $world->group->project_id, 'title' => 'VM group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Completed]);
    Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $vm->id, 'node_id' => null,
        'input' => ['check' => 1, 'repository' => 'example/trial'], 'status' => 'succeeded', 'sequence' => 1, 'attempts' => 1, 'result' => ['pull_request_number' => 9]]);
    noMergeGitHub(headRef: $headRef === null ? null : str_replace('<vm>', (string) $vm->id, $headRef));

    expect(noMergeCode(fn () => app(StartProjectPullRequestMergeAction::class)->execute($other, $number, NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::OtherProjectCode)
        ->and(Operation::query()->where('kind', OperationKinds::ProjectPrMerge)->count())->toBe(0);
})->with(['by its publish' => [null, 9], 'by its task branch' => ['task-<vm>', 10]]);

it('still merges a pull request of its own on a repository that another Project shares', function (): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    noMergeGitHub(headRef: 'feature/hand-made');

    expect(app(StartProjectPullRequestMergeAction::class)->execute($other, 8, NO_MERGE_HEAD, 'test', (string) Str::uuid())->kind)->toBe(OperationKinds::ProjectPrMerge);
});

it('does not count a copy publish to another repository with the same pull request number', function (): void {
    $world = noMergeWorld();
    $elsewhere = Project::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'repository_url' => 'https://github.com/example/elsewhere.git', 'default_branch' => 'main']);

    expect(TaskPullRequest::published($elsewhere, 7))->toBeFalse()->and(TaskPullRequest::published($world->group->project, 7))->toBeTrue();
});

it('refuses task:pr-merge of a group of another Project whose watched pull request a task copy opened on the same repository', function (): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    $group = Task::query()->create(['project_id' => $other->id, 'title' => 'Hand group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running,
        'pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_number' => 7, 'watched_pr_state' => 'open']);
    noMergeGitHub(read: false);

    expect(noMergeCode(fn () => app(TaskPullRequestEvidence::class)->merge($group->refresh(), NO_MERGE_HEAD)))->toBe(TaskPullRequest::Code);
});

it('refuses task:pr-merge when the watched pull request\'s head is a copy group\'s task branch on the same repository', function (): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    $group = Task::query()->create(['project_id' => $other->id, 'title' => 'Hand group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external',
        'pr_url' => 'https://github.com/example/trial/pull/11', 'watched_pr_url' => 'https://github.com/example/trial/pull/11', 'watched_pr_number' => 11, 'watched_pr_state' => 'open']);
    noMergeGitHub(headRef: 'task-'.$world->group->id);

    expect(noMergeCode(fn () => app(TaskPullRequestEvidence::class)->merge($group->refresh(), NO_MERGE_HEAD)))->toBe(TaskPullRequest::Code);
});

it('starts no merge by itself for a task copy group: the gate skips it in its own review-and-merge Project and never sees it from another', function (bool $ownReviews): void {
    $world = noMergeWorld();
    $world->group->project->update(['review_and_merge' => $ownReviews]);
    noMergeOtherProject($world);
    noMergeGitHub(read: false);
    $before = Operation::query()->count();

    $results = app(RunTaskPullRequestGateAction::class)->execute();

    expect($results)->toBe($ownReviews ? [['group' => $world->group->id, 'pull_request' => 7, 'step' => null, 'result' => 'skipped', 'code' => TaskPullRequest::Code, 'operation' => null]] : [])
        ->and(Operation::query()->count())->toBe($before);
})->with(['own Project reviews and merges' => [true], 'only the other Project does' => [false]]);

it('refuses project:pr-merge for the task branch a copy pushed to this repository before its Project named another one', function (): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    Operation::query()->where('kind', OperationKinds::TasksPublish)->update(['status' => 'interrupted', 'result' => null,
        'input' => ['check' => 1, 'repository' => 'example/trial', 'branch' => 'task-'.$world->group->id, 'workspace' => ['branch' => 'task-'.$world->group->id]]]);
    $world->group->update(['status' => TaskGroupStatus::Cancelled, 'taskable_type' => null, 'taskable_id' => null, 'watched_pr_number' => null, 'watched_pr_url' => null]);
    $world->group->project->update(['repository_url' => 'https://github.com/example/moved.git']);
    noMergeGitHub(headRef: 'task-'.$world->group->id);

    expect(noMergeCode(fn () => app(StartProjectPullRequestMergeAction::class)->execute($other, 7, NO_MERGE_HEAD, 'test', (string) Str::uuid())))->toBe(TaskPullRequest::Code);
});

it('skips in task:pr-gate a group of another Project whose watched pull request a task copy opened: no ready step', function (): void {
    $world = noMergeWorld();
    $world->group->project->update(['review_and_merge' => false]);
    $other = noMergeOtherProject($world);
    $group = Task::query()->create(['project_id' => $other->id, 'title' => 'Hand group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external',
        'pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_number' => 7, 'watched_pr_state' => 'open']);
    noMergeGitHub(read: false);
    $before = Operation::query()->count();

    expect(app(RunTaskPullRequestGateAction::class)->execute())->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => null, 'result' => 'skipped', 'code' => TaskPullRequest::Code, 'operation' => null]])
        ->and(Operation::query()->count())->toBe($before);
});

it('refuses the dependency merge of a group of another Project whose pull request a task copy opened', function (): void {
    $world = noMergeWorld();
    $other = noMergeOtherProject($world);
    $group = Task::query()->create(['project_id' => $other->id, 'title' => 'Batch', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external',
        'pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_url' => 'https://github.com/example/trial/pull/7', 'watched_pr_number' => 7, 'watched_pr_state' => 'open']);
    config(['dependency_merge.projects.'.$other->id => ['enabled' => true, 'smoke_workflow' => '.github/workflows/smoke.yml']]);

    expect(noMergeCode(fn () => app(DependencyBatchMergeGuard::class)->lineage($group, 1, NO_MERGE_HEAD)))->toBe(TaskPullRequest::Code);
});

it('skips a task copy group in task:pr-gate of a review-and-merge Project: no ready step, no round, no merge', function (): void {
    $world = noMergeWorld();
    noMergeGitHub(read: false);
    $before = Operation::query()->count();

    $results = app(RunTaskPullRequestGateAction::class)->execute();

    expect($world->group->project->refresh()->reviewsAndMerges())->toBeTrue()
        ->and($results)->toBe([['group' => $world->group->id, 'pull_request' => 7, 'step' => null, 'result' => 'skipped', 'code' => TaskPullRequest::Code, 'operation' => null]])
        ->and(Operation::query()->count())->toBe($before);
});

it('refuses the dependency merge of a batch group that published from a task copy', function (): void {
    $world = noMergeWorld();
    config(['dependency_merge.projects.'.$world->group->project_id => ['enabled' => true, 'smoke_workflow' => '.github/workflows/smoke.yml']]);
    $publish = Operation::query()->where('kind', OperationKinds::TasksPublish)->sole();

    expect(noMergeCode(fn () => app(DependencyBatchMergeGuard::class)->lineage($world->group, $publish->id, NO_MERGE_HEAD)))->toBe(TaskPullRequest::Code);
});

it('does not count a group without a task copy and without a copy publish', function (): void {
    $project = Project::query()->create(['name' => 'Plain', 'slug' => 'plain', 'repository_url' => 'https://github.com/example/plain.git', 'default_branch' => 'main']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Plain', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['check' => 1], 'status' => 'succeeded', 'sequence' => 1, 'attempts' => 1, 'result' => ['pull_request_number' => 9]]);

    expect(TaskPullRequest::group($group->refresh()))->toBeFalse()->and(TaskPullRequest::published($project, 9))->toBeFalse();
});

/**
 * The CI guard: only these sources call GitHub's merge, in Swamp or in the Gateway's own code, and every Operation kind whose workflow is
 * one of them refuses a task copy's pull request with a test in this file. A merge URL counts in any quoting, and unquoted. A new merge
 * path fails here until it refuses too.
 *
 * @param  list<string>  $folders
 * @return list<string>
 */
function noMergeCallers(string $root, array $folders): array
{
    $callers = [];
    foreach ($folders as $folder) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$folder}", FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            $path = substr($file->getPathname(), strlen($root) + 1);
            if (! preg_match('/\.(ya?ml|ts|py|php)$/', $path) || preg_match('/(_test\.|\/tests\/)/', $path)) {
                continue;
            }
            if (preg_match(NO_MERGE_CALL, (string) file_get_contents($file->getPathname())) === 1) {
                $callers[] = $path;
            }
        }
    }
    sort($callers);

    return $callers;
}

const NO_MERGE_CALL = '/pulls\/[^\n]*\/merge\b|merge_method|mergePullRequest|gh pr merge|PullRequestAutoMerge/';

it('knows every Swamp source that calls GitHub\'s merge, and each kind that runs one has a copy refusal test', function (): void {
    expect(noMergeCallers(base_path('app'), ['.']))->toBe([])
        ->and(preg_match(NO_MERGE_CALL, "\$path = 'repos/'.\$repository.'/pulls/'.\$number.'/merge';"))->toBe(1)
        ->and(preg_match(NO_MERGE_CALL, 'url: https://api.github.com/repos/example/trial/pulls/7/merge'))->toBe(1)
        ->and(preg_match(NO_MERGE_CALL, '# diff/merge drivers stay off'))->toBe(0);
    $callers = noMergeCallers(base_path('../../swamp'), ['workflows', 'extensions', 'ansible']);

    expect($callers)->toBe([
        'extensions/behaviors/operations/dependency-merge/mod.ts',
        'workflows/workflow-operation-dependency-merge.yaml',
        'workflows/workflow-operation-task-pr-merge.yaml',
    ]);

    $workflows = ['operation-dependency-merge', 'operation-task-pr-merge'];
    $kinds = array_keys(array_filter(app(DirectOperation::class)->definitions(), static fn (array $definition): bool => in_array($definition['workflow'] ?? null, $workflows, true)));
    sort($kinds);
    $refusals = ['project.pr_merge' => 'refuses project:pr-merge', 'tasks.dependency_merge' => 'refuses the dependency merge', 'tasks.pr_merge' => 'refuses task:pr-merge'];

    expect($kinds)->toBe(array_keys($refusals));
    $source = (string) file_get_contents(__FILE__);
    foreach ($refusals as $title) {
        expect($source)->toContain("it('{$title}");
    }
});
