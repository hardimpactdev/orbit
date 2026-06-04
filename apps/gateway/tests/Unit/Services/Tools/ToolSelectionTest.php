<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstanceSelector;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRuntimeSelection;
use App\Services\Tools\ToolVersionRequest;
use Tests\TestCase;

uses(TestCase::class);

describe('tool selector and version resolution', function (): void {
    it('normalizes optional version input before catalog resolution', function (): void {
        $request = ToolVersionRequest::fromInput(' 8.4 ');
        $empty = ToolVersionRequest::fromInput(null);

        expect($request->value)->toBe('8.4')
            ->and($request->isEmpty())->toBeFalse()
            ->and($empty->value)->toBeNull()
            ->and($empty->isEmpty())->toBeTrue();
    });

    it('resolves major version input to a MySQL version-family instance', function (): void {
        $selection = ToolInstanceSelector::forInstall(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            version: ToolVersionRequest::fromInput('8'),
            instance: null,
        );

        expect($selection)->toBeInstanceOf(ToolInstanceSelector::class)
            ->and($selection->tool)->toBe('mysql')
            ->and($selection->instanceKey)->toBe('mysql:8')
            ->and($selection->versionFamily)->toBe('8')
            ->and($selection->expectedVersion)->toBe('8.4');
    });

    it('resolves specific version input to its owning MySQL family instance', function (): void {
        $selection = ToolInstanceSelector::forInstall(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            version: ToolVersionRequest::fromInput('8.4'),
            instance: null,
        );

        expect($selection)->toBeInstanceOf(ToolInstanceSelector::class)
            ->and($selection->tool)->toBe('mysql')
            ->and($selection->instanceKey)->toBe('mysql:8')
            ->and($selection->versionFamily)->toBe('8')
            ->and($selection->expectedVersion)->toBe('8.4');
    });

    it('fails unsupported MySQL version requests before side effects', function (string $version): void {
        $failure = ToolInstanceSelector::forInstall(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            version: ToolVersionRequest::fromInput($version),
            instance: null,
        );

        expect($failure)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($failure->code)->toBe('tool.version_unsupported')
            ->and($failure->meta)->toMatchArray([
                'field' => 'version',
                'tool' => 'mysql',
                'version' => $version,
            ]);
    })->with([
        'unsupported major' => '10',
        'unsupported specific' => '8.5',
    ]);

    it('resolves default and explicit runtime choices for supported node platforms', function (): void {
        $default = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: null,
            platform: 'ubuntu',
        );

        $swarm = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: 'docker-swarm',
            platform: 'ubuntu',
        );

        expect($default)->toBeInstanceOf(ToolRuntimeSelection::class)
            ->and($default->runtime)->toBe('docker')
            ->and($default->platform)->toBe('ubuntu')
            ->and($swarm)->toBeInstanceOf(ToolRuntimeSelection::class)
            ->and($swarm->runtime)->toBe('docker-swarm')
            ->and($swarm->platform)->toBe('ubuntu');
    });

    it('separates unsupported runtimes from unsupported runtime platforms', function (): void {
        $unsupportedRuntime = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: 'podman',
            platform: 'ubuntu',
        );

        $unsupportedPlatform = ToolRuntimeSelection::resolve(
            catalog: app(ToolCatalog::class),
            tool: 'mysql',
            runtime: 'docker-swarm',
            platform: 'macos',
        );

        expect($unsupportedRuntime)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($unsupportedRuntime->code)->toBe('tool.runtime_unsupported')
            ->and($unsupportedRuntime->meta)->toMatchArray([
                'tool' => 'mysql',
                'runtime' => 'podman',
            ])
            ->and($unsupportedPlatform)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($unsupportedPlatform->code)->toBe('tool.runtime_platform_unsupported')
            ->and($unsupportedPlatform->meta)->toMatchArray([
                'tool' => 'mysql',
                'runtime' => 'docker-swarm',
                'platform' => 'macos',
            ]);
    });
});
