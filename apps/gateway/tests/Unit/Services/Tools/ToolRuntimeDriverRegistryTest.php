<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRuntimeDriverRegistry;
use App\Services\Tools\ToolRuntimeSelection;
use Tests\TestCase;

uses(TestCase::class);

it('resolves Docker and Swarm drivers through normalized target platforms', function (
    string $runtime,
    string $expectedImplementationKey,
    string $expectedDriverLabel,
): void {
    $registry = new ToolRuntimeDriverRegistry([
        new RecordingToolRuntimeDriver('docker/ubuntu', 'standalone Docker'),
        new RecordingToolRuntimeDriver('docker-swarm/ubuntu', 'Swarm service'),
    ]);

    $selection = toolRuntimeDriverSelection('mysql', $runtime, 'ubuntu_24-04');

    $driver = $registry->resolve($selection);

    expect($driver)->toBeInstanceOf(RecordingToolRuntimeDriver::class)
        ->and($driver->implementationKey())->toBe($expectedImplementationKey)
        ->and($driver->label())->toBe($expectedDriverLabel);
})->with([
    'standalone docker' => ['docker', 'docker/ubuntu', 'standalone Docker'],
    'swarm service' => ['docker-swarm', 'docker-swarm/ubuntu', 'Swarm service'],
]);

it('allows new runtime platform implementations to register without changing command selection', function (): void {
    $registry = new ToolRuntimeDriverRegistry([
        new RecordingToolRuntimeDriver('custom/ubuntu', 'Custom Ubuntu runtime'),
    ]);

    $selection = new ToolRuntimeSelection(
        tool: 'custom-tool',
        runtime: 'custom',
        platform: 'ubuntu_24-04',
        nodePlatform: 'ubuntu_24-04',
        platformFamily: 'ubuntu',
        implementationKey: 'custom/ubuntu',
    );

    $driver = $registry->resolve($selection);

    expect($driver)->toBeInstanceOf(RecordingToolRuntimeDriver::class)
        ->and($driver->implementationKey())->toBe('custom/ubuntu');
});

it('rejects unsupported node runtime implementations before driver side effects', function (): void {
    $registry = new ToolRuntimeDriverRegistry([
        new RecordingToolRuntimeDriver('docker/ubuntu', 'standalone Docker'),
    ]);

    $selection = new ToolRuntimeSelection(
        tool: 'mysql',
        runtime: 'docker',
        platform: 'freebsd_14',
        nodePlatform: 'freebsd_14',
        platformFamily: 'freebsd',
        implementationKey: 'docker/freebsd',
    );

    $failure = $registry->resolve($selection);

    expect($failure)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($failure->code)->toBe('tool.runtime_platform_unsupported')
        ->and($failure->meta)->toMatchArray([
            'tool' => 'mysql',
            'runtime' => 'docker',
            'platform' => 'freebsd_14',
            'platform_family' => 'freebsd',
            'implementation_key' => 'docker/freebsd',
        ]);
});

it('carries unsupported runtime and unsupported platform failures without touching drivers', function (): void {
    $registry = new ToolRuntimeDriverRegistry([
        new RecordingToolRuntimeDriver('docker/ubuntu', 'standalone Docker'),
    ]);

    $unsupportedRuntime = ToolRuntimeSelection::resolve(
        catalog: app(ToolCatalog::class),
        tool: 'mysql',
        runtime: 'podman',
        platform: 'ubuntu_24-04',
    );

    $unsupportedPlatform = ToolRuntimeSelection::resolve(
        catalog: app(ToolCatalog::class),
        tool: 'mysql',
        runtime: 'docker-swarm',
        platform: 'macos_15-4',
    );

    expect($registry->resolve($unsupportedRuntime))->toBe($unsupportedRuntime)
        ->and($unsupportedRuntime)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($unsupportedRuntime->code)->toBe('tool.runtime_unsupported')
        ->and($registry->resolve($unsupportedPlatform))->toBe($unsupportedPlatform)
        ->and($unsupportedPlatform)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($unsupportedPlatform->code)->toBe('tool.runtime_platform_unsupported');
});

function toolRuntimeDriverSelection(string $tool, string $runtime, string $platform): ToolRuntimeSelection
{
    $selection = ToolRuntimeSelection::resolve(
        catalog: app(ToolCatalog::class),
        tool: $tool,
        runtime: $runtime,
        platform: $platform,
    );

    expect($selection)->toBeInstanceOf(ToolRuntimeSelection::class);

    return $selection;
}

final readonly class RecordingToolRuntimeDriver
{
    public function __construct(
        private string $implementationKey,
        private string $label,
    ) {}

    public function implementationKey(): string
    {
        return $this->implementationKey;
    }

    public function label(): string
    {
        return $this->label;
    }
}
