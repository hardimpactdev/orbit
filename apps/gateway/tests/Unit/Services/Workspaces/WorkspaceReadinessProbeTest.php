<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('retries transient unhealthy workspace responses', function (): void {
    $probe = new WorkspaceReadinessProbe(maxAttempts: 3, retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return (
            $attempts < 3
                ? [
                    'url' => 'https://feature.docs.test',
                    'result' => 'unhealthy',
                    'status_code' => $attempts === 1 ? 500 : 502,
                    'duration_ms' => 1,
                ]
                : [
                    'url' => 'https://feature.docs.test',
                    'result' => 'healthy',
                    'status_code' => 200,
                    'duration_ms' => 1,
                ]
        );
    });

    expect($result)
        ->toBe([
            'url' => 'https://feature.docs.test',
            'result' => 'healthy',
            'status_code' => 200,
            'duration_ms' => 1,
        ])
        ->and($attempts)
        ->toBe(3);
});

it('returns the last unhealthy readiness result after all attempts fail', function (): void {
    $probe = new WorkspaceReadinessProbe(maxAttempts: 2, retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return [
            'url' => 'https://feature.docs.test',
            'result' => 'unhealthy',
            'status_code' => $attempts === 1 ? 502 : null,
            'duration_ms' => 1,
        ];
    });

    expect($result)
        ->toBe([
            'url' => 'https://feature.docs.test',
            'result' => 'unhealthy',
            'status_code' => null,
            'duration_ms' => 1,
        ])
        ->and($attempts)
        ->toBe(2);
});

it('keeps default readiness retries within the setup probe budget', function (): void {
    $probe = new WorkspaceReadinessProbe(retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return [
            'url' => 'https://feature.docs.test',
            'result' => 'unhealthy',
            'status_code' => 500,
            'duration_ms' => 1,
        ];
    });

    expect($result['result'])
        ->toBe('unhealthy')
        ->and($result['status_code'])
        ->toBe(500)
        ->and($attempts)
        ->toBe(10);
});

it('does not retry non-transient workspace configuration failures', function (): void {
    $probe = new WorkspaceReadinessProbe(maxAttempts: 3, retryDelayMilliseconds: 0);
    $attempts = 0;

    $result = $probe->probeWith(function () use (&$attempts): array {
        $attempts++;

        return [
            'url' => 'https://feature.docs.test',
            'result' => 'unhealthy',
            'status_code' => 404,
            'duration_ms' => 1,
        ];
    });

    expect($result['result'])
        ->toBe('unhealthy')
        ->and($result['status_code'])
        ->toBe(404)
        ->and($attempts)
        ->toBe(1);
});

it('fails readiness when vite module assets are not reachable', function (): void {
    $workspace = workspaceForReadinessProbe();
    $url = $workspace->url();

    Http::preventStrayRequests();
    Http::fake([
        $url => Http::response(<<<HTML
            <html>
                <head>
                    <script type="module" src="{$url}/@vite/client"></script>
                    <script type="module" src="{$url}/resources/js/app.ts"></script>
                </head>
            </html>
            HTML),
        "{$url}/@vite/client" => Http::response('Not found', 404),
    ]);

    $result = new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0)->probe($workspace);

    expect($result)
        ->toHaveKeys(['url', 'result', 'status_code', 'duration_ms'])
        ->and($result['url'])
        ->toBe($url)
        ->and($result['result'])
        ->toBe('unhealthy')
        ->and($result['status_code'])
        ->toBe(404)
        ->and($result['duration_ms'])
        ->toBeInt();
});

it('passes readiness when vite module assets are reachable', function (): void {
    $workspace = workspaceForReadinessProbe();
    $url = $workspace->url();
    $viteUrl = "{$url}:5186";

    Http::preventStrayRequests();
    Http::fake([
        $url => Http::response(<<<HTML
            <html>
                <head>
                    <script type="module" src="{$viteUrl}/@vite/client"></script>
                    <script type="module" src="{$viteUrl}/resources/js/app.ts"></script>
                </head>
            </html>
            HTML),
        "{$viteUrl}/@vite/client" => Http::response('ok'),
        "{$viteUrl}/resources/js/app.ts" => Http::response('ok'),
    ]);

    $result = new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0)->probe($workspace);

    expect($result)
        ->toHaveKeys(['url', 'result', 'status_code', 'duration_ms'])
        ->and($result['url'])
        ->toBe($url)
        ->and($result['result'])
        ->toBe('healthy')
        ->and($result['status_code'])
        ->toBe(200)
        ->and($result['duration_ms'])
        ->toBeInt();
});

it('reports page probe exceptions without exposing their messages', function (): void {
    $workspace = workspaceForReadinessProbe();
    $sentinel = 'private-page-probe-sentinel';
    Exceptions::fake();
    Http::fake(fn (): never => throw new RuntimeException($sentinel));

    $result = new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0)->probe($workspace);

    expect($result)
        ->toHaveKeys(['url', 'result', 'status_code', 'duration_ms'])
        ->and($result['result'])
        ->toBe('unhealthy')
        ->and($result['status_code'])
        ->toBeNull()
        ->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $sentinel);
});

it('reports vite probe exceptions without exposing their messages', function (): void {
    $workspace = workspaceForReadinessProbe();
    $url = $workspace->url();
    $sentinel = 'private-vite-probe-sentinel';
    Exceptions::fake();
    Http::fake(function (Request $request) use ($url, $sentinel) {
        if ($request->url() === $url) {
            return Http::response("<script type=\"module\" src=\"{$url}/@vite/client\"></script>");
        }

        throw new RuntimeException($sentinel);
    });

    $result = new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0)->probe($workspace);

    expect($result)
        ->toHaveKeys(['url', 'result', 'status_code', 'duration_ms'])
        ->and($result['result'])
        ->toBe('unhealthy')
        ->and($result['status_code'])
        ->toBeNull()
        ->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $sentinel);
});

function workspaceForReadinessProbe(): Workspace
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()
        ->placedOn($node)
        ->create([
            'name' => 'docs',
        ]);

    return Workspace::factory()
        ->for($app)
        ->for($app->instances()->firstOrFail(), 'instance')
        ->create([
            'name' => 'feature',
        ]);
}
