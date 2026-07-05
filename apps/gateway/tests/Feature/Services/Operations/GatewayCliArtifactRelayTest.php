<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.url', 'http://gateway.test');
    config()->set('orbit.updates.artifact_base_url', 'http://gateway.test');
    config()->set('orbit.paths.config_root', storage_path('framework/testing/update-artifacts'));

    File::deleteDirectory((string) config('orbit.paths.config_root'));
});

afterEach(function (): void {
    File::deleteDirectory((string) config('orbit.paths.config_root'));
});

it('uses public manifest CLI artifact URLs directly without staging them on the gateway', function (): void {
    $binary = 'orbit-linux-binary';
    $sha256 = hash('sha256', $binary);
    $run = gatewayCliArtifactRelayRun();
    $plan = gatewayCliArtifactRelayPlan($run, $sha256);

    Http::fake();

    $artifact = app(GatewayCliArtifactRelay::class)->artifactFor($run, $plan, 'linux-amd64');

    expect($artifact)
        ->toBe([
            'url' => 'https://artifacts.example/orbit-linux-amd64',
            'sha256' => $sha256,
            'source_url' => 'https://artifacts.example/orbit-linux-amd64',
        ])
        ->and(File::isDirectory(storage_path("framework/testing/update-artifacts/update-artifacts/{$run->id}")))
        ->toBeFalse();

    Http::assertNothingSent();
});

it('does not touch the artifact cache when all manifest CLI artifacts are public URLs', function (): void {
    config()->set('orbit.updates.artifact_cache_ttl_seconds', 1);

    $expired = storage_path('framework/testing/update-artifacts/update-artifacts/expired-run');
    File::ensureDirectoryExists($expired);
    touch($expired, time() - 10);

    $binary = 'orbit-linux-binary';
    $sha256 = hash('sha256', $binary);
    $run = gatewayCliArtifactRelayRun();
    $plan = gatewayCliArtifactRelayPlan($run, $sha256);

    app(GatewayCliArtifactRelay::class)->stage($run, $plan);

    expect(File::isDirectory($expired))->toBeTrue();
});

it('stages a local-only manifest CLI artifact and serves it through the gateway endpoint', function (): void {
    $binary = 'orbit-linux-binary';
    $sha256 = hash('sha256', $binary);
    $run = gatewayCliArtifactRelayRun();
    $plan = gatewayCliArtifactRelayPlan($run, $sha256, artifactUrl: 'http://localhost/orbit-linux-amd64');

    Http::fake([
        'http://localhost/orbit-linux-amd64' => Http::response($binary, 200),
    ]);

    $artifact = app(GatewayCliArtifactRelay::class)->artifactFor($run, $plan, 'linux-amd64');

    expect($artifact)
        ->toMatchArray([
            'sha256' => $sha256,
            'source_url' => 'http://localhost/orbit-linux-amd64',
        ])
        ->and($artifact['url'])
        ->toStartWith("http://gateway.test/api/update/artifacts/{$run->id}/cli/linux-amd64?token=");

    $response = $this->get(gatewayCliArtifactRelayPathFromUrl($artifact['url']));

    $response->assertOk();
    expect(File::get($response->baseResponse->getFile()->getPathname()))->toBe($binary);
    Http::assertSentCount(1);
});

it('uses the registered gateway address when the configured artifact base url is local only', function (): void {
    config()->set('app.url', 'http://localhost');
    config()->set('orbit.updates.artifact_base_url', 'http://localhost');

    Node::factory()
        ->gateway()
        ->create([
            'host' => 'gateway.internal',
            'wireguard_address' => '10.6.0.2',
        ]);

    $binary = 'orbit-linux-binary';
    $sha256 = hash('sha256', $binary);
    $run = gatewayCliArtifactRelayRun();
    $plan = gatewayCliArtifactRelayPlan($run, $sha256, artifactUrl: 'http://localhost/orbit-linux-amd64');

    Http::fake([
        'http://localhost/orbit-linux-amd64' => Http::response($binary, 200),
    ]);

    $artifact = app(GatewayCliArtifactRelay::class)->artifactFor($run, $plan, 'linux-amd64');

    expect($artifact['url'])->toStartWith("https://10.6.0.2/api/update/artifacts/{$run->id}/cli/linux-amd64?token=");
});

it('rejects gateway artifact downloads with an invalid token', function (): void {
    $binary = 'orbit-linux-binary';
    $run = gatewayCliArtifactRelayRun();
    $plan = gatewayCliArtifactRelayPlan(
        $run,
        hash('sha256', $binary),
        artifactUrl: 'http://localhost/orbit-linux-amd64',
    );

    Http::fake([
        'http://localhost/orbit-linux-amd64' => Http::response($binary, 200),
    ]);

    app(GatewayCliArtifactRelay::class)->artifactFor($run, $plan, 'linux-amd64');

    $this->get("/api/update/artifacts/{$run->id}/cli/linux-amd64?token=bad-token")
        ->assertForbidden();
});

it('fails staging when the downloaded artifact hash does not match the manifest', function (): void {
    $run = gatewayCliArtifactRelayRun();
    $plan = gatewayCliArtifactRelayPlan($run, str_repeat('b', 64), artifactUrl: 'http://localhost/orbit-linux-amd64');

    Http::fake([
        'http://localhost/orbit-linux-amd64' => Http::response('wrong-binary', 200),
    ]);

    expect(fn () => app(GatewayCliArtifactRelay::class)->artifactFor($run, $plan, 'linux-amd64'))
        ->toThrow(RuntimeException::class, 'CLI artifact hash mismatch');
});

it('removes expired artifact cache directories before staging', function (): void {
    config()->set('orbit.updates.artifact_cache_ttl_seconds', 1);

    $expired = storage_path('framework/testing/update-artifacts/update-artifacts/expired-run');
    File::ensureDirectoryExists($expired);
    touch($expired, time() - 10);

    app(GatewayCliArtifactRelay::class)->cleanupExpired();

    expect(File::isDirectory($expired))->toBeFalse();
});

function gatewayCliArtifactRelayRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function gatewayCliArtifactRelayPlan(
    OperationRun $run,
    string $sha256,
    string $artifactUrl = 'https://artifacts.example/orbit-linux-amd64',
): OperationUpdatePlan {
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:'.str_repeat('a', 64);

    return OperationUpdatePlan::query()->create([
        'operation_run_id' => $run->id,
        'target_version' => '2.0.0',
        'gateway_image' => $gatewayImage,
        'manifest_source' => 'topology-candidate',
        'manifest_version' => '2.0.0',
        'manifest_snapshot' => [
            'version' => '2.0.0',
            'source' => 'topology-candidate',
            'build_id' => 'candidate-build',
            'images' => ['gateway' => $gatewayImage],
            'cli_artifacts' => [
                'linux-amd64' => [
                    'url' => $artifactUrl,
                    'sha256' => $sha256,
                ],
            ],
            'role_images' => [],
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => $artifactUrl,
                'sha256' => $sha256,
            ],
        ],
        'role_images' => [],
    ]);
}

function gatewayCliArtifactRelayPathFromUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);

    if (! is_string($path) || ! is_string($query)) {
        throw new RuntimeException("Invalid artifact URL [{$url}].");
    }

    return "{$path}?{$query}";
}
