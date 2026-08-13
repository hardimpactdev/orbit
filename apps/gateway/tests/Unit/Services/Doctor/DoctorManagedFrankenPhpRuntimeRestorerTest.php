<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Doctor\DoctorManagedFrankenPhpRuntimeRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('ignores a missing-process issue without a concrete app instance', function (): void {
    $node = Node::factory()->appDev()->create();

    expect(app(DoctorManagedFrankenPhpRuntimeRestorer::class)->restoreMissingProcess(
        $node,
        'process.runtime_unit_missing',
        [],
    ))->toBeNull();
});

it('ignores process rows without a supported managed runtime label', function (array $runtimeConfig): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->for($node, 'node')->create();
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            document_root: $app->document_root,
        ),
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'runtime_config' => $runtimeConfig,
        ]);

    expect(app(DoctorManagedFrankenPhpRuntimeRestorer::class)->restoreManagedRuntime(
        $node,
        'process.runtime_unit_mismatch',
        $process,
    ))->toBeNull();
})->with([
    'no label' => [[]],
    'unknown label' => [[
        'container_spec_hash_label' => 'unknown.spec_hash',
    ]],
]);

it('reports an app process placed on a different node without applying runtime changes', function (): void {
    $servingNode = Node::factory()->appDev()->create(['name' => 'serving-node']);
    $requestedNode = Node::factory()->appDev()->create(['name' => 'requested-node']);
    $app = App::factory()->for($servingNode, 'node')->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $servingNode->id,
            path: $app->path,
            document_root: $app->document_root,
        ),
    ]);
    $process = Process::factory()
        ->forOwner($app, $servingNode)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'frankenphp-docs',
            'runtime_config' => [
                'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
            ],
        ]);

    expect(app(DoctorManagedFrankenPhpRuntimeRestorer::class)->restoreManagedRuntime(
        $requestedNode,
        'process.runtime_unit_mismatch',
        $process,
    ))->toBe([
        'family' => 'process',
        'node' => 'requested-node',
        'code' => 'process.runtime_unit_mismatch',
        'key' => 'process.runtime_unit_mismatch',
        'mode' => 'restore',
        'status' => 'failed',
        'summary' => 'Failed to restore process.runtime_unit_mismatch.',
        'details' => [
            'app' => 'docs',
            'instance' => 'development',
            'process' => 'frankenphp-docs',
            'error' => 'Process instance has no active serving node.',
        ],
    ]);
});
