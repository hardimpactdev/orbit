<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeUnitName;
use Database\Factories\ProcessFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns the full identity when it fits the shared backend limit', function (): void {
    [$app, $process] = runtime_unit_name_fixture(
        appName: 'docs',
        instanceName: 'development',
        processName: 'vite',
    );

    expect(ProcessRuntimeUnitName::for($app, $process))
        ->toBe('orbit_docs_development_main_vite')
        ->and(ProcessRuntimeUnitName::isValid('orbit_docs_development_main_vite'))
        ->toBeTrue();
});

it('deterministically bounds long identities so launchd labels stay valid', function (): void {
    [$app, $process, $workspace] = runtime_unit_name_fixture(
        appName: 'laravel-toolbar',
        instanceName: 'development',
        processName: 'inertia-ssr',
        workspaceName: 'filament-pages-og-images',
    );

    $full = 'orbit_laravel-toolbar_development_filament-pages-og-images_inertia-ssr';
    $bounded = ProcessRuntimeUnitName::for($app, $process, $workspace);

    expect(strlen($full))
        ->toBeGreaterThan(ProcessRuntimeUnitName::MaxLength)
        ->and(ProcessRuntimeUnitName::isValid($full))
        ->toBeFalse()
        ->and($bounded)
        ->not
        ->toBe($full)
        ->and(strlen($bounded))
        ->toBeLessThanOrEqual(ProcessRuntimeUnitName::MaxLength)
        ->and(ProcessRuntimeUnitName::isValid($bounded))
        ->toBeTrue()
        ->and($bounded)
        ->toStartWith('orbit_')
        ->and(ProcessRuntimeUnitName::for($app, $process, $workspace))
        ->toBe($bounded);
});

/**
 * @return array{0: Project, 1: OrbitProcess, 2?: Workspace}
 */
function runtime_unit_name_fixture(
    string $appName,
    string $instanceName,
    string $processName,
    ?string $workspaceName = null,
): array {
    $node = Node::factory()
        ->appDev()
        ->create([
            'platform' => 'macos_14',
            'status' => 'active',
        ]);
    $app = Project::factory()->for($node, 'node')->create(['name' => $appName]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => $instanceName,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/Users/orbit/apps/'.$appName,
        ),
    ]);
    $factory = OrbitProcess::factory();
    if (! $factory instanceof ProcessFactory) {
        throw new RuntimeException('Process factory missing.');
    }

    $process = $factory
        ->forOwner($app)
        ->create([
            'name' => $processName,
            'app_instance_id' => $instance->id,
            'node_id' => $node->id,
        ]);

    if ($workspaceName === null) {
        return [$app, $process];
    }

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'name' => $workspaceName,
        'path' => '/Users/orbit/apps/'.$appName.'/'.$workspaceName,
    ]);

    return [$app, $process, $workspace];
}
