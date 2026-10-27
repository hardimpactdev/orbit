<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
});

it('stores GitHub merge times on ended tasks and leaves the others alone', function (): void {
    $project = Project::query()->create([
        'name' => 'Backfill App', 'slug' => 'backfill-app',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);
    // Tasks that ended before lifecycle times existed have no ended_at.
    $group = static function (string $status, ?string $url, array $times = []) use ($project): Task {
        $task = Task::topLevel()->forceCreate(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief.', 'status' => $status, 'pr_url' => $url]);
        DB::table('tasks')->where('id', $task->id)->update(['ended_at' => null, ...$times]);

        return $task;
    };
    $transferred = $group('completed', 'https://github.com/old-owner/orbit/pull/7');
    $ended = $group('cancelled', 'https://github.com/acme/orbit/pull/8', ['ended_at' => '2026-10-02 08:00:00']);
    $closed = $group('completed', 'https://github.com/acme/orbit/pull/9');
    $failedRead = $group('failed', 'https://github.com/acme/orbit/pull/10');
    $open = $group('settling', 'https://github.com/acme/orbit/pull/11');
    $known = $group('completed', 'https://github.com/acme/orbit/pull/12', ['merged_at' => '2026-10-03 09:00:00', 'ended_at' => '2026-10-03 09:00:00']);
    $updatedAt = $transferred->fresh()?->updated_at;
    $this->travel(1)->hours();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_backfill'], 201),
        'https://api.github.com/repos/old-owner/orbit/pulls/7' => Http::response(['merged' => true, 'state' => 'closed', 'merged_at' => '2026-10-01T10:00:00Z']),
        'https://api.github.com/repos/acme/orbit/pulls/8' => Http::response(['merged' => true, 'state' => 'closed', 'merged_at' => '2026-10-02T09:30:00Z']),
        'https://api.github.com/repos/acme/orbit/pulls/9' => Http::response(['merged' => false, 'state' => 'closed', 'merged_at' => null]),
        'https://api.github.com/repos/acme/orbit/pulls/10' => Http::response(['message' => 'Not Found'], 404),
    ]);

    expect(Artisan::call('orbit:tasks:backfill-merged-at'))->toBe(0)
        ->and(Artisan::output())->toContain('Stored the merge time of 2 tasks. 1 did not merge; 1 could not be read.');

    $times = static fn (Task $task): array => [
        $task->fresh()?->merged_at?->toDateTimeString(), $task->fresh()?->ended_at?->toDateTimeString(),
    ];
    expect($times($transferred))->toBe(['2026-10-01 10:00:00', '2026-10-01 10:00:00'])
        ->and($transferred->fresh()?->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and($times($ended))->toBe(['2026-10-02 09:30:00', '2026-10-02 08:00:00'])
        ->and($times($closed))->toBe([null, null])
        ->and($closed->fresh()?->pullRequestMerged())->toBeFalse()
        ->and($times($failedRead)[0])->toBeNull()
        ->and($times($open))->toBe([null, null])
        ->and($times($known))->toBe(['2026-10-03 09:00:00', '2026-10-03 09:00:00'])
        ->and($transferred->fresh()?->pullRequestMerged())->toBeTrue();
    Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/pulls/11') || str_contains($request->url(), '/pulls/12'));
});
