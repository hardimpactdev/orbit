<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('orbit.artifacts.disk', 'orbit-artifacts');
    config()->set('orbit.artifacts.base_url', 'https://artifacts.orbit/releases');

    Storage::fake('orbit-artifacts');
});

it('activates a stable release candidate channel from an immutable candidate manifest', function (): void {
    candidateManifestFixture('20260622T120000Z-abc123');

    $exitCode = Artisan::call('orbit:release-candidate:activate', [
        'buildId' => '20260622T120000Z-abc123',
        '--channel' => 'live-test',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Activated release candidate channel [live-test].')
        ->and($output)->toContain('https://artifacts.orbit/releases/channels/live-test/orbit-release-manifest.json');

    Storage::disk('orbit-artifacts')->assertExists('channels/live-test/orbit-release-manifest.json');

    $channelManifest = json_decode(
        Storage::disk('orbit-artifacts')->get('channels/live-test/orbit-release-manifest.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($channelManifest)->toMatchArray([
        'source' => 'topology-candidate',
        'build_id' => '20260622T120000Z-abc123',
        'version' => '1.2.3',
    ]);
});

it('emits a JSON success envelope for automation', function (): void {
    candidateManifestFixture('20260622T120000Z-abc123');

    $exitCode = Artisan::call('orbit:release-candidate:activate', [
        'buildId' => '20260622T120000Z-abc123',
        '--channel' => 'live-test',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toMatchArray([
            'success' => [
                'data' => [
                    'channel' => 'live-test',
                    'build_id' => '20260622T120000Z-abc123',
                    'source_path' => 'candidates/20260622T120000Z-abc123/orbit-release-manifest.candidate.json',
                    'channel_path' => 'channels/live-test/orbit-release-manifest.json',
                    'manifest_url' => 'https://artifacts.orbit/releases/channels/live-test/orbit-release-manifest.json',
                    'version' => '1.2.3',
                ],
            ],
        ]);
});

it('rejects unsafe build ids and channel names', function (): void {
    $this->artisan('orbit:release-candidate:activate', [
        'buildId' => '../candidate',
        '--channel' => 'live-test',
    ])
        ->expectsOutputToContain('Candidate build id [../candidate] is not path safe.')
        ->assertFailed();

    $this->artisan('orbit:release-candidate:activate', [
        'buildId' => '20260622T120000Z-abc123',
        '--channel' => '../live-test',
    ])
        ->expectsOutputToContain('Candidate channel [../live-test] is not path safe.')
        ->assertFailed();
});

it('rejects manifests that do not match the requested candidate build id', function (): void {
    candidateManifestFixture('20260622T120000Z-abc123', manifestBuildId: 'other-build');

    $this->artisan('orbit:release-candidate:activate', [
        'buildId' => '20260622T120000Z-abc123',
        '--channel' => 'live-test',
    ])
        ->expectsOutputToContain('Candidate manifest build id [other-build] does not match [20260622T120000Z-abc123].')
        ->assertFailed();
});

it('requires a configured artifact base URL', function (): void {
    config()->set('orbit.artifacts.base_url', null);

    candidateManifestFixture('20260622T120000Z-abc123');

    $this->artisan('orbit:release-candidate:activate', [
        'buildId' => '20260622T120000Z-abc123',
        '--channel' => 'live-test',
    ])
        ->expectsOutputToContain('ORBIT_ARTIFACTS_BASE_URL is required to activate a release candidate channel.')
        ->assertFailed();
});

function candidateManifestFixture(string $buildId, ?string $manifestBuildId = null): void
{
    Storage::disk('orbit-artifacts')->put(
        "candidates/{$buildId}/orbit-release-manifest.candidate.json",
        json_encode([
            'schema_version' => 1,
            'version' => '1.2.3',
            'build_id' => $manifestBuildId ?? $buildId,
            'source' => 'topology-candidate',
            'images' => [
                'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3-candidate-test@sha256:'.str_repeat('a', 64),
            ],
            'cli_artifacts' => [
                'linux-amd64' => [
                    'url' => "https://artifacts.orbit/releases/candidates/{$buildId}/orbit-linux-x64",
                    'sha256' => str_repeat('b', 64),
                ],
            ],
            'role_images' => [
                'orbit-caddy' => 'caddy:2-alpine',
            ],
        ], JSON_THROW_ON_ERROR),
        'public',
    );
}
