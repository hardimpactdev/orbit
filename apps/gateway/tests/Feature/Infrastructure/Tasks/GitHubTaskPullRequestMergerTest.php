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
        'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => static fn (Request $request) => Http::response(['total_count' => 2, 'check_runs' => [
            ['id' => 2, 'name' => 'Gateway shards (1)', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions']],
            ['id' => 1, 'name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => MERGER_SHA, 'app' => ['slug' => 'github-actions']],
        ]]),
    ]);
}

function merger_check_run_reads(): int
{
    return count(Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/check-runs')));
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

it('reads no check runs without the checks permission, and asks again on the next read', function (): void {
    GitHubTestSupport::storeApp();
    merger_fake_checks(422);
    $merger = app(GitHubTaskPullRequestMerger::class);
    $group = merger_group();

    expect($merger->checkRuns($group, MERGER_SHA))->toBeNull()
        ->and($merger->requiredCheck($group, MERGER_SHA, 'Required checks'))->toBe(RequiredCheckState::Unreadable)
        ->and(merger_check_run_reads())->toBe(0);
});
