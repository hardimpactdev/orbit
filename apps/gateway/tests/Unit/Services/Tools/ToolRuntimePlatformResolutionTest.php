<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRuntimeSelection;
use Tests\TestCase;

uses(TestCase::class);

describe('tool runtime platform resolution', function (): void {
    it('normalizes recorded node platforms before resolving runtime implementations', function (
        string $nodePlatform,
        string $platformFamily,
        string $implementationKey,
    ): void {
        $selection = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: 'docker',
            platform: $nodePlatform,
        );

        expect($selection)->toBeInstanceOf(ToolRuntimeSelection::class)
            ->and($selection->nodePlatform)->toBe($nodePlatform)
            ->and($selection->platformFamily)->toBe($platformFamily)
            ->and($selection->implementationKey)->toBe($implementationKey);
    })->with([
        'linux family' => ['linux', 'linux', 'docker/linux'],
        'ubuntu family' => ['ubuntu', 'ubuntu', 'docker/ubuntu'],
        'versioned ubuntu family' => ['ubuntu_24-04', 'ubuntu', 'docker/ubuntu'],
    ]);

    it('resolves Linux Docker and Swarm implementations from versioned Ubuntu platforms', function (
        string $runtime,
        string $implementationKey,
    ): void {
        $selection = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: $runtime,
            platform: 'ubuntu_24-04',
        );

        expect($selection)->toBeInstanceOf(ToolRuntimeSelection::class)
            ->and($selection->runtime)->toBe($runtime)
            ->and($selection->nodePlatform)->toBe('ubuntu_24-04')
            ->and($selection->platformFamily)->toBe('ubuntu')
            ->and($selection->implementationKey)->toBe($implementationKey);
    })->with([
        'standalone docker' => ['docker', 'docker/ubuntu'],
        'swarm docker' => ['docker-swarm', 'docker-swarm/ubuntu'],
    ]);

    it('rejects Swarm on macOS platforms before runtime side effects', function (): void {
        $failure = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: 'docker-swarm',
            platform: 'macos_15-4',
        );

        expect($failure)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($failure->code)->toBe('tool.runtime_platform_unsupported')
            ->and($failure->meta)->toMatchArray([
                'tool' => 'mysql',
                'runtime' => 'docker-swarm',
                'platform' => 'macos_15-4',
                'platform_family' => 'macos',
                'implementation_key' => 'docker-swarm/macos',
            ]);
    });
});
