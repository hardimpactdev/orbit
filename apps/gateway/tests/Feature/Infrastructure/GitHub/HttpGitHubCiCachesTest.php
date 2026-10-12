<?php

declare(strict_types=1);

use App\Domain\Projects\CiCacheArchives;
use App\Domain\Projects\CiCacheSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

const CI_CACHE_SHA = 'cccccccccccccccccccccccccccccccccccccccc';

const CI_CACHE_OLDER_SHA = 'dddddddddddddddddddddddddddddddddddddddd';

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function ci_cache_run(int $id = 9, string $sha = CI_CACHE_SHA, array $changes = []): array
{
    return array_replace(['id' => $id, 'head_branch' => 'main', 'head_sha' => $sha, 'event' => 'push', 'status' => 'completed', 'conclusion' => 'success'], $changes);
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function ci_cache_artifact(int $id, int $job, int $run = 9, string $sha = CI_CACHE_SHA, array $changes = []): array
{
    return array_replace([
        'id' => $id, 'name' => "sandbox-tia-{$job}-{$sha}", 'expired' => false, 'size_in_bytes' => 100,
        'workflow_run' => ['id' => $run, 'head_sha' => $sha],
    ], $changes);
}

/**
 * @param  list<array<string, mixed>>  $runs
 * @param  array<int, list<array<string, mixed>>>  $artifacts  run id => artifacts
 */
function ci_cache_fixture(array $runs, array $artifacts, bool $permissionFailure = false): void
{
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    $responses = [
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 7]),
        'https://api.github.com/app/installations/7/access_tokens' => $permissionFailure
            ? Http::response(['message' => 'ci-token-secret'], 403)
            : Http::response(['token' => 'ci-token-secret', 'permissions' => ['actions' => 'read']]),
        'https://api.github.com/repos/acme/orbit/actions/workflows/ci.yml/runs?branch=main&status=success&per_page=20' => Http::response(['workflow_runs' => $runs]),
    ];
    foreach ($artifacts as $run => $rows) {
        $responses["https://api.github.com/repos/acme/orbit/actions/runs/{$run}/artifacts?per_page=100"] = Http::response(['total_count' => count($rows), 'artifacts' => $rows]);
        foreach ($rows as $row) {
            $responses["https://api.github.com/repos/acme/orbit/actions/artifacts/{$row['id']}/zip"] = Http::response('', 302, ['Location' => "https://productionresultssa1.blob.core.windows.net/a/{$row['id']}?sig=signed-secret"]);
            $responses["https://productionresultssa1.blob.core.windows.net/a/{$row['id']}?sig=signed-secret"] = Http::response("archive-{$row['id']}");
        }
    }
    Http::fake($responses);
}

function ci_cache_project(): Project
{
    return new Project(['repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main']);
}

describe('Gateway GitHub CI main caches', function (): void {
    it('downloads every main cache artifact of the newest successful run that has them', function (): void {
        ci_cache_fixture(
            [ci_cache_run(10, CI_CACHE_SHA), ci_cache_run(9, CI_CACHE_OLDER_SHA)],
            [10 => [], 9 => [ci_cache_artifact(22, 1, 9, CI_CACHE_OLDER_SHA), ci_cache_artifact(21, 0, 9, CI_CACHE_OLDER_SHA), ['id' => 30, 'name' => 'web-dist-'.CI_CACHE_OLDER_SHA]]],
        );

        $caches = app(CiCacheSource::class)->fetch(ci_cache_project());

        expect($caches->commit)->toBe(CI_CACHE_OLDER_SHA)
            ->and($caches->runId)->toBe(9)
            ->and($caches->archives)->toBe(['archive-21', 'archive-22']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/7/access_tokens'
            && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['actions' => 'read']]);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://productionresultssa1.blob.core.windows.net/')
            && ! $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/artifacts/30/'));
    });

    it('fails with a named error when no successful run has main caches', function (array $run, array $artifact): void {
        ci_cache_fixture([ci_cache_run(9, CI_CACHE_SHA, $run)], [9 => [ci_cache_artifact(21, 0, 9, CI_CACHE_SHA, $artifact)]]);

        expect(fn () => app(CiCacheSource::class)->fetch(ci_cache_project()))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.ci_caches_missing'));
    })->with([
        'pull request run' => [['event' => 'pull_request'], []],
        'feature branch run' => [['head_branch' => 'feature'], []],
        'failed run' => [['conclusion' => 'failure'], []],
        'another commit' => [[], ['name' => 'sandbox-tia-0-'.CI_CACHE_OLDER_SHA]],
    ]);

    it('refuses an expired, oversized, or foreign artifact of a run as unreadable', function (array $artifact): void {
        ci_cache_fixture([ci_cache_run()], [9 => [ci_cache_artifact(21, 0, 9, CI_CACHE_SHA, $artifact)]]);

        expect(fn () => app(CiCacheSource::class)->fetch(ci_cache_project()))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.ci_caches_unavailable'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/zip'));
    })->with([
        'expired' => [['expired' => true]],
        'oversized' => [['size_in_bytes' => CiCacheArchives::MaxBytes + 1]],
        'another run' => [['workflow_run' => ['id' => 8, 'head_sha' => CI_CACHE_SHA]]],
    ]);

    it('redacts permission failures and never forwards the token', function (): void {
        ci_cache_fixture([ci_cache_run()], [9 => [ci_cache_artifact(21, 0)]], permissionFailure: true);

        try {
            app(CiCacheSource::class)->fetch(ci_cache_project());
            test()->fail('Expected unreadable caches.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('instance.ci_caches_unavailable')
                ->and($exception->getMessage())->toContain('Actions read')->not->toContain('ci-token-secret');
        }
    });

    it('refuses a Project without a GitHub origin or default branch without network', function (Project $project): void {
        Http::preventStrayRequests();

        expect(fn () => app(CiCacheSource::class)->fetch($project))->toThrow(ResourceOperationException::class, 'Actions read');
        Http::assertNothingSent();
    })->with([
        'another host' => fn (): Project => new Project(['repository_url' => 'https://gitlab.com/acme/orbit.git', 'default_branch' => 'main']),
        'no default branch' => fn (): Project => new Project(['repository_url' => 'https://github.com/acme/orbit.git']),
    ]);
});
