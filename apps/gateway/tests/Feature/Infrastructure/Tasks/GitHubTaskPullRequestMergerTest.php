<?php

declare(strict_types=1);

use App\Domain\GitHub\RequiredCheckState;
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

function merger_fake_checks(int $checksTokenStatus = 201): void
{
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => $checksTokenStatus === 201
            ? Http::response(['token' => 'ghs_checks'], 201)
            : Http::response(['message' => 'The permissions requested are not granted to this installation.'], $checksTokenStatus),
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
        && str_contains($request->url(), 'event=pull_request') && str_contains($request->url(), 'head_sha='.MERGER_SHA));
})->with([
    'no pull request run yet' => [[], RequiredCheckState::Missing],
    'the pull request run is another suite' => [[['id' => 8, 'event' => 'pull_request', 'head_sha' => MERGER_SHA, 'check_suite_id' => 6]], RequiredCheckState::Missing],
    'the run is the pull request run' => [[['id' => 8, 'event' => 'pull_request', 'head_sha' => MERGER_SHA, 'check_suite_id' => 5]], RequiredCheckState::Passed],
]);
