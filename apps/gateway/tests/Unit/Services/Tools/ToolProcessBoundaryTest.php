<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceCatalog;
use App\Services\Tools\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps service runtime configuration in managed service processes instead of tools', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);
    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'mysql',
        version: '8',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'mysql8',
    );

    expect(app(ToolCatalog::class)->supports('mysql'))
        ->toBeFalse()
        ->and($descriptor->runtimeConfig)
        ->toMatchArray([
            'service' => 'mysql',
            'version_family' => '8',
            'version' => '8.4',
            'service_name' => 'orbit-mysql8',
        ])
        ->and($descriptor->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('mysql')
        ->and($descriptor->runtimeConfig['labels']['orbit.process.version_family'])
        ->toBe('8')
        ->and($descriptor->runtimeConfig['labels'])
        ->not->toHaveKey('orbit.tool')->and($descriptor->runtimeConfig['labels'])
        ->not->toHaveKey('orbit.tool_instance');
});
