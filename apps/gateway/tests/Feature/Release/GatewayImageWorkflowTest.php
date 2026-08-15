<?php

declare(strict_types=1);

it('builds pull requests without publishing and publishes only immutable sha tags from main', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));

    expect($workflow)
        ->toContain('name: Orbit Gateway Image')
        ->toContain('push:')
        ->toContain('pull_request:')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain("if: github.event_name != 'pull_request'")
        ->toContain('output_mode="--load"')
        ->toContain('output_mode="--push"')
        ->toContain('short_sha="${GITHUB_SHA::12}"')
        ->toContain('--tag "${image}:sha-${short_sha}"')
        ->toContain('docker buildx build')
        ->toContain('--metadata-file')
        ->toContain('containerimage.digest')
        ->toContain('orbit-gateway-image-metadata')
        ->not->toContain('Read canonical version')
        ->not->toContain('bin/orbit-version')
        ->not->toContain('steps.version.outputs.value')
        ->not->toContain('--tag "${image}:${VERSION}"')
        ->not->toContain('orbit'.'-runtime');

    expect(substr_count($workflow, '--tag '))->toBe(1);
});
