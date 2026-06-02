<?php

declare(strict_types=1);

it('publishes cli artifacts gateway image and release manifest on GitHub releases', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('name: Orbit Release')
        ->toContain('types: [published]')
        ->toContain('packages: write')
        ->toContain('contents: write')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('docker buildx build')
        ->toContain('--push')
        ->toContain('--metadata-file')
        ->toContain('containerimage.digest')
        ->toContain('vendor/bin/phpacker build mac arm')
        ->toContain('vendor/bin/phpacker build linux x64')
        ->toContain('bin/orbit-release-manifest')
        ->toContain('orbit-release-manifest.json')
        ->toContain('gh release upload')
        ->toContain('orbit-linux-x64')
        ->toContain('orbit-macos-arm64')
        ->not->toContain('orbit'.'-runtime');
});

it('keeps gateway image build hygiene covered by dockerignore in release workflow context', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));
    $dockerignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect($workflow)
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('docker/orbit-gateway/Dockerfile.dockerignore');

    expect($dockerignore)
        ->toContain('**/.env')
        ->toContain('**/vendor')
        ->toContain('storage/logs')
        ->toContain('storage/*.sqlite')
        ->toContain('node_modules')
        ->toContain('.git');
});
