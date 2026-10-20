<?php

declare(strict_types=1);

use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Tasks\GitHubTaskPullRequestMerger;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

const MERGER_SHA = '1111111111111111111111111111111111111111';

function merger_group(): Task
{
    $project = Project::query()->create([
        'name' => 'Merger App', 'slug' => 'merger-app', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);

    return Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Merge', 'brief' => 'Merge it.', 'status' => 'settling'])->load('project');
}

function merger_fake_checks(int $checksTokenStatus = 201, array $workflows = ['total_count' => 0, 'workflow_runs' => []]): void
{
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => static fn () => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn () => $checksTokenStatus === 201
            ? Http::response(['token' => 'ghs_checks', 'permissions' => ['actions' => 'read']], 201)
            : Http::response(['message' => 'The permissions requested are not granted to this installation.'], $checksTokenStatus),
        'https://api.github.com/repos/acme/orbit/actions/runs*' => static fn () => Http::response($workflows),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => 1, 'check_suites' => [
            ['id' => 5, 'status' => 'completed', 'conclusion' => 'success', 'app' => ['slug' => 'github-actions']],
        ]]),
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => static fn (Request $request) => Http::response(['total_count' => 2, 'check_runs' => [
            ['id' => 2, 'name' => 'Gateway shards (1)', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions']],
            ['id' => 1, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions']],
        ]]),
    ]);
}

function merger_check_run_reads(string $path = '/check-runs'): int
{
    return count(Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $path)));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('shares one check-run read of a commit between the merge check and the run list for a minute', function (): void {
    GitHubTestSupport::storeApp();
    merger_fake_checks();
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Passed)
        ->and(array_map(static fn ($run): string => $run->name, $merger->checkRuns($group, MERGER_SHA) ?? []))->toBe(['Gateway shards (1)', 'Required checks'])
        ->and($merger->requiredCheck($group, MERGER_SHA, 'Gateway shards (1)'))->toBe(RequiredCheckState::Passed)
        ->and(merger_check_run_reads())->toBe(1);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'check_name='));

    $this->travel(61)->seconds();
    $merger->checkRuns($group, MERGER_SHA);

    expect(merger_check_run_reads())->toBe(2);
});

it('reads the check suites of a commit before its check runs, as one snapshot', function (): void {
    GitHubTestSupport::storeApp();
    merger_fake_checks();
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect(array_map(static fn ($suite): ?string => $suite->conclusion, $merger->commitChecks($group, MERGER_SHA)['suites'] ?? []))->toBe(['success'])
        ->and($merger->checkRuns($group, MERGER_SHA))->toHaveCount(2)
        ->and($merger->commitChecks($group, MERGER_SHA)['runs'] ?? [])->toHaveCount(2)
        ->and(merger_check_run_reads('/check-runs'))->toBe(1)
        ->and(merger_check_run_reads('/check-suites'))->toBe(1);
    $reads = array_values(array_filter(array_map(static fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH), Http::recorded()->all()),
        static fn (string $path): bool => str_contains($path, '/check-')));
    // A suite completes only after its runs, so a completed suite in the snapshot always comes with its final runs.
    expect($reads)->toBe(['/repos/acme/orbit/commits/'.MERGER_SHA.'/check-suites', '/repos/acme/orbit/commits/'.MERGER_SHA.'/check-runs']);
});

it('reads no check runs without the checks permission, and asks again on the next read', function (): void {
    GitHubTestSupport::storeApp();
    merger_fake_checks(422);
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect($merger->checkRuns($group, MERGER_SHA))->toBeNull()
        ->and($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Unreadable)
        ->and(merger_check_run_reads())->toBe(0);
});

it('counts for the pull request only a merge-check run of its own pull_request workflow run', function (array $workflowRuns, RequiredCheckState $state): void {
    GitHubTestSupport::storeApp();
    // Closures, because the Actions reader streams a body, and one shared response can be read only once.
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => static fn () => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn () => Http::response(['token' => 'ghs_read', 'permissions' => ['checks' => 'read', 'actions' => 'read']], 201),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => 1, 'check_suites' => [
            ['id' => 5, 'status' => 'completed', 'conclusion' => 'success', 'app' => ['slug' => 'github-actions']],
        ]]),
        // The handoff push run of this commit passed, in check suite 5.
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => Http::response(['total_count' => 1, 'check_runs' => [
            ['id' => 1, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA,
                'app' => ['slug' => 'github-actions'], 'check_suite' => ['id' => 5]],
        ]]),
        'https://api.github.com/repos/acme/orbit/actions/runs*' => static fn () => Http::response(['total_count' => count($workflowRuns), 'workflow_runs' => $workflowRuns]),
    ]);
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect($merger->pullRequestCheck($group, MERGER_SHA, 'Required checks'))->toBe($state)
        ->and($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Passed);
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/actions/runs?')
        && ! str_contains($request->url(), 'event=') && str_contains($request->url(), 'head_sha='.MERGER_SHA));
})->with([
    'no pull request run yet' => [[], RequiredCheckState::Missing],
    'the pull request run is another suite' => [[['id' => 8, 'workflow_id' => 10, 'head_branch' => 'task-7', 'event' => 'pull_request', 'head_sha' => MERGER_SHA, 'check_suite_id' => 6]], RequiredCheckState::Missing],
    'the run is the pull request run' => [[['id' => 8, 'workflow_id' => 10, 'head_branch' => 'task-7', 'event' => 'pull_request', 'head_sha' => MERGER_SHA, 'check_suite_id' => 5]], RequiredCheckState::Passed],
]);

it('keeps other workflows, events, and branches when it removes superseded suites', function (int $workflow, string $event, ?string $branch, array $expected, ?string $newestBranch = 'task-7-handoff'): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => static fn () => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn () => Http::response(['token' => 'ghs_read', 'permissions' => ['checks' => 'read', 'actions' => 'read']], 201),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => 3, 'check_suites' => [
            ['id' => 5, 'status' => 'completed', 'conclusion' => 'cancelled', 'app' => ['slug' => 'github-actions']],
            ['id' => 6, 'status' => 'completed', 'conclusion' => 'success', 'app' => ['slug' => 'github-actions']],
            ['id' => 7, 'status' => 'completed', 'conclusion' => 'failure', 'app' => ['slug' => 'github-actions']],
        ]]),
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => Http::response(['total_count' => 3, 'check_runs' => [
            // The obsolete job has no replacement of its name: the whole suite must be removed.
            ['id' => 1, 'name' => 'Old job', 'status' => 'completed', 'conclusion' => 'cancelled', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions'], 'check_suite' => ['id' => 5]],
            ['id' => 2, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions'], 'check_suite' => ['id' => 6]],
            ['id' => 3, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions'], 'check_suite' => ['id' => 7]],
        ]]),
        'https://api.github.com/repos/acme/orbit/actions/runs*' => static fn () => Http::response(['total_count' => 3, 'workflow_runs' => [
            ['id' => 19, 'workflow_id' => $workflow, 'event' => $event, 'head_branch' => $branch, 'head_sha' => MERGER_SHA, 'check_suite_id' => 5],
            ['id' => 21, 'workflow_id' => 11, 'event' => 'push', 'head_branch' => 'task-7-handoff', 'head_sha' => MERGER_SHA, 'check_suite_id' => 7],
            ['id' => 20, 'workflow_id' => 10, 'event' => 'push', 'head_branch' => $newestBranch, 'head_sha' => MERGER_SHA, 'check_suite_id' => 6],
        ]]),
    ]);
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    $checks = $merger->commitChecks($group, MERGER_SHA);

    expect(array_map(static fn ($suite): int => $suite->id, $checks['suites'] ?? []))->toBe($expected)
        ->and(array_map(static fn ($run): ?int => $run->checkSuiteId, $checks['runs'] ?? []))->toBe($expected)
        ->and($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Failed);
})->with([
    'superseded run, API order differs from run id order' => [10, 'push', 'task-7-handoff', [6, 7]],
    'another workflow' => [12, 'push', 'task-7-handoff', [5, 6, 7]],
    'pull request workflow must remain' => [10, 'pull_request', 'task-7-handoff', [5, 6, 7]],
    'an unrelated null branch' => [10, 'push', null, [5, 6, 7]],
    'two runs with null branches' => [10, 'push', null, [6, 7], null],
    'null and empty branches are distinct' => [10, 'push', null, [5, 6, 7], ''],
    'another branch' => [10, 'push', 'main', [5, 6, 7]],
]);

it('shares the newest pull request suites with every commit reader even before the new suite appears', function (array $suiteIds, array $expectedSuites): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => static fn () => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn () => Http::response(['token' => 'ghs_read', 'permissions' => ['checks' => 'read', 'actions' => 'read']], 201),
        'https://api.github.com/repos/acme/orbit/commits/*/check-suites*' => Http::response(['total_count' => count($suiteIds), 'check_suites' => array_map(
            static fn (int $id): array => ['id' => $id, 'status' => 'completed', 'conclusion' => $id === 5 ? 'cancelled' : 'success', 'app' => ['slug' => 'github-actions']],
            $suiteIds,
        )]),
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => Http::response(['total_count' => 2, 'check_runs' => [
            ['id' => 1, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions'], 'check_suite' => ['id' => 5]],
            ['id' => 2, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions'], 'check_suite' => ['id' => 6]],
        ]]),
        'https://api.github.com/repos/acme/orbit/actions/runs*' => static fn () => Http::response(['total_count' => 2, 'workflow_runs' => [
            ['id' => 19, 'workflow_id' => 10, 'event' => 'pull_request', 'head_branch' => 'task-7', 'head_sha' => MERGER_SHA, 'check_suite_id' => 5],
            ['id' => 20, 'workflow_id' => 10, 'event' => 'pull_request', 'head_branch' => 'task-7', 'head_sha' => MERGER_SHA, 'check_suite_id' => 6],
        ]]),
    ]);
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect($merger->pullRequestCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Passed)
        ->and($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Passed)
        ->and(array_map(static fn ($run): ?int => $run->checkSuiteId, $merger->checkRuns($group, MERGER_SHA) ?? []))->toBe([6])
        ->and(array_map(static fn ($suite): int => $suite->id, $merger->commitChecks($group, MERGER_SHA)['suites'] ?? []))->toBe($expectedSuites)
        ->and(merger_check_run_reads('/actions/runs'))->toBe(1);
    $paths = array_map(static fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_PATH), Http::recorded()->all());
    expect(array_search('/repos/acme/orbit/commits/'.MERGER_SHA.'/check-runs', $paths))->toBeLessThan(array_search('/repos/acme/orbit/actions/runs', $paths));

    $this->travel(61)->seconds();
    expect($merger->pullRequestCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Passed)
        ->and(merger_check_run_reads('/actions/runs'))->toBe(2);
})->with([
    'both suites visible' => [[5, 6], [6]],
    'new suite not in the earlier suite read' => [[5], []],
]);

it('fails every commit reader on an incomplete or malformed workflow list without caching it', function (array $response): void {
    GitHubTestSupport::storeApp();
    merger_fake_checks(workflows: $response);
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Unreadable)
        ->and($merger->pullRequestCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Unreadable)
        ->and(fn () => $merger->commitChecks($group, MERGER_SHA))->toThrow(TaskPullRequestException::class)
        ->and(merger_check_run_reads('/actions/runs'))->toBe(3);
})->with([
    'incomplete list' => [['total_count' => 1, 'workflow_runs' => []]],
    'malformed row' => [['total_count' => 1, 'workflow_runs' => [['id' => 8, 'head_sha' => MERGER_SHA]]]],
    'another SHA' => [['total_count' => 1, 'workflow_runs' => [['id' => 8, 'workflow_id' => 10, 'event' => 'pull_request', 'head_branch' => null, 'head_sha' => 'another', 'check_suite_id' => 5]]]],
]);
