<?php

declare(strict_types=1);

use App\Services\Operations\LocalFleetUpdateInstallCliFailure;
use App\Services\Operations\LocalFleetUpdateInstallCliPayload;

it('rejects a role image alias whose source is not a required image', function (): void {
    $candidateImage =
        'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-build@sha256:'.str_repeat('a', times: 64);

    expect(fn (): LocalFleetUpdateInstallCliPayload => LocalFleetUpdateInstallCliPayload::fromArray([
        'artifact_url' => 'https://artifacts.test/orbit',
        'sha256' => str_repeat('b', times: 64),
        'install_root' => '/home/orbit/orbit',
        'bin_path' => '/home/orbit/.local/bin/orbit',
        'shared_binary_path' => null,
        'role_images' => [],
        'role_image_aliases' => [[
            'source' => $candidateImage,
            'target' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        ]],
    ]))
        ->toThrow(
            LocalFleetUpdateInstallCliFailure::class,
            'Fleet update CLI install payload is invalid.',
        );
});

it('accepts an archive-backed role image alias whose source is the required local tag', function (): void {
    $candidateImage = 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-build';

    $payload = LocalFleetUpdateInstallCliPayload::fromArray([
        'artifact_url' => 'https://artifacts.test/orbit',
        'sha256' => str_repeat('b', times: 64),
        'install_root' => '/home/orbit/orbit',
        'bin_path' => '/home/orbit/.local/bin/orbit',
        'shared_binary_path' => null,
        'role_images' => [$candidateImage],
        'role_image_aliases' => [[
            'source' => $candidateImage,
            'target' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        ]],
    ]);

    expect($payload->roleImageAliases)
        ->toHaveCount(1)
        ->and($payload->roleImageAliases[0]->source)
        ->toBe($candidateImage);
});
