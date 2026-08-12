<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DoctorIssueDisposition;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorProcessRestoreSupport;
use App\Services\Processes\ProcessesProbe;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use App\Services\Processes\ProcessRuntimeUnitLivenessDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('reports an enabled launchd runtime unit whose label is not loaded', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_26-5-1',
            'user' => 'orbit',
        ]);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'node-exporter',
            'runtime' => ProcessRuntime::Launchd,
            'restart_policy' => ProcessRestartPolicy::Never,
        ]);
    $unit = app(ProcessExpectedRuntimeUnits::class)->specifications($process)[0];

    $drift = app(ProcessRuntimeUnitLivenessDiff::class)->diff($process, new ProbeSnapshot([
        'node-exporter' => [
            'runtime_backend_available' => true,
            'runtime_unit_renderable' => true,
            'runtime_units' => [
                $unit['name'] => [
                    'config_exists' => true,
                    'config_matches' => true,
                    'loaded' => false,
                ],
            ],
        ],
    ]));
    $issue = app(DoctorIssueFactory::class)->fromDriftEntry($drift[0], $node->name);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('process.runtime_unit_unloaded')
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Divergent)
        ->and($drift[0]->summary)
        ->toBe("Launchd runtime unit {$unit['name']} is not loaded in the current user GUI domain.")
        ->and($drift[0]->detail)
        ->toMatchArray([
            'process' => 'node-exporter',
            'runtime' => 'launchd',
            'runtime_unit' => $unit['name'],
            'expected' => $unit['config_path'],
            'observed_loaded' => false,
        ])
        ->and($issue->disposition)
        ->toBe(DoctorIssueDisposition::RuntimeIncident)
        ->and($issue->restorable)
        ->toBeFalse()
        ->and($issue->adoptable)
        ->toBeFalse()
        ->and(DoctorProcessRestoreSupport::supports('process.runtime_unit_unloaded'))
        ->toBeFalse();
});

it('does not report a launchd runtime unit whose label is loaded', function (): void {
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_26-5-1',
            'user' => 'orbit',
        ]);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'node-exporter',
            'runtime' => ProcessRuntime::Launchd,
        ]);
    $unit = app(ProcessExpectedRuntimeUnits::class)->specifications($process)[0];

    $drift = app(ProcessRuntimeUnitLivenessDiff::class)->diff($process, new ProbeSnapshot([
        'node-exporter' => [
            'runtime_backend_available' => true,
            'runtime_unit_renderable' => true,
            'runtime_units' => [
                $unit['name'] => [
                    'config_exists' => true,
                    'config_matches' => true,
                    'loaded' => true,
                ],
            ],
        ],
    ]));

    expect($drift)->toBeEmpty();
});

it('reports launchd file mismatch before unloaded state', function (): void {
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_26-5-1',
            'user' => 'orbit',
        ]);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'node-exporter',
            'runtime' => ProcessRuntime::Launchd,
        ]);
    $unit = app(ProcessExpectedRuntimeUnits::class)->specifications($process)[0];

    $drift = app(ProcessesProbe::class)->diff($process, new ProbeSnapshot([
        'node-exporter' => [
            'runtime_backend_available' => true,
            'runtime_unit_renderable' => true,
            'runtime_units' => [
                $unit['name'] => [
                    'config_exists' => true,
                    'config_matches' => false,
                    'loaded' => false,
                ],
            ],
            'runtime_unit_extras' => [],
        ],
    ]));

    expect(array_column($drift, 'key'))->toBe([
        'process.runtime_unit_mismatch',
        'process.runtime_unit_unloaded',
    ]);
});

it('does not report an app development launchd runtime unit that is intentionally unloaded', function (): void {
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_26-5-1',
            'user' => 'orbit',
        ]);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/Users/orbit/apps/docs',
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);
    $process = Process::factory()
        ->forOwner($app)
        ->create([
            'instance_id' => $instance->id,
            'node_id' => $node->id,
            'name' => 'vite',
            'runtime' => ProcessRuntime::Launchd,
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);
    $unit = app(ProcessExpectedRuntimeUnits::class)->specifications($process)[0];

    $drift = app(ProcessRuntimeUnitLivenessDiff::class)->diff($process, new ProbeSnapshot([
        'vite' => [
            'runtime_backend_available' => true,
            'runtime_unit_renderable' => true,
            'runtime_units' => [
                $unit['name'] => [
                    'config_exists' => true,
                    'config_matches' => true,
                    'loaded' => false,
                ],
            ],
        ],
    ]));

    expect($drift)->toBeEmpty();
});

it('leaves a missing launchd runtime unit to the definition check', function (): void {
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_26-5-1',
            'user' => 'orbit',
        ]);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'node-exporter',
            'runtime' => ProcessRuntime::Launchd,
        ]);
    $unit = app(ProcessExpectedRuntimeUnits::class)->specifications($process)[0];

    $drift = app(ProcessRuntimeUnitLivenessDiff::class)->diff($process, new ProbeSnapshot([
        'node-exporter' => [
            'runtime_backend_available' => true,
            'runtime_unit_renderable' => true,
            'runtime_units' => [
                $unit['name'] => [
                    'config_exists' => false,
                    'config_matches' => false,
                    'loaded' => false,
                ],
            ],
        ],
    ]));

    expect($drift)->toBeEmpty();
});
