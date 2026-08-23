<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\Operations\ReleaseManifest;

function release_manifest_desktop_fixture(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'version' => '1.2.3',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', times: 64),
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-x64',
                'sha256' => str_repeat('b', times: 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:'.str_repeat('d', times: 64),
        ],
    ], $overrides);
}

it('accepts a legacy release manifest without desktop artifacts', function (): void {
    $manifest = ReleaseManifest::fromArray(release_manifest_desktop_fixture());

    expect($manifest->desktopArtifacts)->toBeEmpty();
});

it('parses optional Darwin desktop artifacts without weakening CLI validation', function (): void {
    $desktop = [
        'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/Orbit.app.tar.gz',
        'sha256' => str_repeat('c', times: 64),
        'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
        'version' => '1.2.3',
        'platform' => 'darwin',
        'architecture' => 'arm64',
    ];

    $manifest = ReleaseManifest::fromArray(release_manifest_desktop_fixture([
        'desktop_artifacts' => [
            'darwin-arm64' => $desktop,
        ],
    ]));

    expect($manifest->desktopArtifacts['darwin-arm64'])->toBe($desktop);
});

it('rejects desktop artifacts that omit a Tauri updater signature', function (): void {
    expect(fn () => ReleaseManifest::fromArray(release_manifest_desktop_fixture([
        'desktop_artifacts' => [
            'darwin-arm64' => [
                'url' => 'https://example.test/Orbit.app.tar.gz',
                'sha256' => str_repeat('c', times: 64),
                'version' => '1.2.3',
                'platform' => 'darwin',
                'architecture' => 'arm64',
            ],
        ],
    ])))
        ->toThrow(RuntimeException::class, 'signature');
});

it('rejects desktop artifacts whose version differs from the release manifest version', function (): void {
    expect(fn () => ReleaseManifest::fromArray(release_manifest_desktop_fixture([
        'desktop_artifacts' => [
            'darwin-arm64' => [
                'url' => 'https://example.test/Orbit.app.tar.gz',
                'sha256' => str_repeat('c', times: 64),
                'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
                'version' => '1.2.2',
                'platform' => 'darwin',
                'architecture' => 'arm64',
            ],
        ],
    ])))
        ->toThrow(RuntimeException::class, 'version');
});

it('rejects persisted desktop artifacts whose version differs from the plan target version', function (): void {
    $desktop = [
        'url' => 'https://example.test/Orbit.app.tar.gz',
        'sha256' => str_repeat('c', times: 64),
        'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
        'version' => '1.2.2',
        'platform' => 'darwin',
        'architecture' => 'arm64',
    ];

    expect(fn () => OperationUpdatePlanSnapshot::fromArray([
        'target_version' => '1.2.3',
        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', times: 64),
        'manifest_source' => 'github-release',
        'manifest_version' => '1.2.3',
        'manifest_snapshot' => release_manifest_desktop_fixture([
            'desktop_artifacts' => ['darwin-arm64' => $desktop],
        ]),
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://example.test/orbit-linux-x64',
                'sha256' => str_repeat('b', times: 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:'.str_repeat('d', times: 64),
        ],
        'desktop_artifacts' => [
            'darwin-arm64' => $desktop,
        ],
    ]))
        ->toThrow(RuntimeException::class, 'version');
});

it('persists optional desktop artifacts on an immutable update plan snapshot', function (): void {
    $desktop = [
        'url' => 'https://example.test/Orbit.app.tar.gz',
        'sha256' => str_repeat('c', times: 64),
        'signature' => 'dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==',
        'version' => '1.2.3',
        'platform' => 'darwin',
        'architecture' => 'arm64',
    ];
    $snapshot = OperationUpdatePlanSnapshot::fromArray([
        'target_version' => '1.2.3',
        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', times: 64),
        'manifest_source' => 'github-release',
        'manifest_version' => '1.2.3',
        'manifest_snapshot' => release_manifest_desktop_fixture([
            'desktop_artifacts' => ['darwin-arm64' => $desktop],
        ]),
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://example.test/orbit-linux-x64',
                'sha256' => str_repeat('b', times: 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:'.str_repeat('d', times: 64),
        ],
        'desktop_artifacts' => [
            'darwin-arm64' => $desktop,
        ],
    ]);

    expect($snapshot->desktopArtifacts['darwin-arm64'])
        ->toBe($desktop)
        ->and($snapshot->toArray()['desktop_artifacts']['darwin-arm64'])
        ->toBe($desktop);
});
