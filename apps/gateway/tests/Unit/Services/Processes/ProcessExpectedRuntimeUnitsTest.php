<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('builds app systemd units in main then workspace id order', function (): void {
    $node = createTestAppHostNode(['name' => 'app-development']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $first = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    $second = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-b',
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'vite',
            'runtime' => ProcessRuntime::Systemd,
        ]);

    $specifications = app(ProcessExpectedRuntimeUnits::class)->specifications($process);

    expect(array_column($specifications, 'name'))
        ->toBe([
            'orbit_docs_development_main_vite',
            "orbit_docs_development_{$first->name}_vite",
            "orbit_docs_development_{$second->name}_vite",
        ])
        ->and($specifications[0]['config_path'])
        ->toBe('/etc/systemd/system/orbit_docs_development_main_vite.service')
        ->and($specifications[0]['environment_lines'])
        ->not->toBeEmpty();
});

it('preserves configured Docker hash and label overrides', function (): void {
    $node = Node::factory()->database()->create(['name' => 'database-1']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'valkey',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'image' => 'valkey/valkey:8.1',
                'container_spec_hash' => 'configured-hash',
                'container_spec_hash_label' => 'orbit.custom.hash',
            ],
        ]);

    $specifications = app(ProcessExpectedRuntimeUnits::class)->specifications($process);

    expect($specifications)
        ->toHaveCount(1)
        ->and($specifications[0])
        ->toMatchArray([
            'name' => 'valkey',
            'config_hash' => 'configured-hash',
            'config_hash_label' => 'orbit.custom.hash',
        ]);
});

it('uses the Docker Swarm label hash when no top-level hash exists', function (): void {
    $node = Node::factory()->database()->create(['name' => 'database-1']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'mysql8',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'service_name' => 'orbit-mysql8',
                'labels' => [ProcessDockerContainer::SpecHashLabel => 'label-hash'],
            ],
        ]);

    expect(app(ProcessExpectedRuntimeUnits::class)->specifications($process)[0])->toMatchArray([
        'name' => 'orbit-mysql8',
        'config_hash' => 'label-hash',
        'config_hash_label' => ProcessDockerContainer::SpecHashLabel,
    ]);
});

it('rejects app launchd units on Linux placement', function (): void {
    $node = createTestAppHostNode(['name' => 'beast', 'platform' => 'ubuntu_24-04']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'vite',
            'runtime' => ProcessRuntime::Launchd,
        ]);

    $expectedUnits = app(ProcessExpectedRuntimeUnits::class);

    expect($expectedUnits->names($process))
        ->toBe(['orbit_docs_development_main_vite'])
        ->and(fn () => $expectedUnits->specifications($process))
        ->toThrow(InvalidArgumentException::class, 'requires a macOS execution node');
});
