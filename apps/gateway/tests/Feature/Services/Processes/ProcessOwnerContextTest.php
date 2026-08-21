<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessOwnerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the workspace managed frankenphp runtime and keeps inherited non-web processes in workspace lifecycle order', function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-dev-1',
        'platform' => 'ubuntu_24-04',
        'tld' => 'test',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
    ]);

    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'php_version' => '8.5',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);

    $workspace = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-docs',
    ]);

    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'frankenphp-docs',
            'runtime' => ProcessRuntime::Docker,
            'sort_order' => 0,
        ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'queue',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 10,
        ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'vite',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 20,
        ]);

    $workspaceRuntime = app(EnsureFrankenPhpRuntimeProcess::class)->forWorkspace($workspace);

    $context = ProcessOwnerContext::forWorkspace($node, $workspace, $instance);

    $processes = $context->lifecycleProcesses(null);

    expect($processes->pluck('name')->all())
        ->toBe([
            $workspaceRuntime->name,
            'queue',
            'vite',
        ])
        ->and($processes->firstWhere('name', $workspaceRuntime->name)?->owner?->is($workspace))
        ->toBeTrue()
        ->and($processes->contains(fn (Process $process): bool => $process->name === 'frankenphp-docs'))
        ->toBeFalse();
});
