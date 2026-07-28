<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Processes\ProcessRuntime;
use App\Models\AppInstance;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Workspaces\WorkspaceShowPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the effective workspace process set for the selected instance only', function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-dev-1',
        'platform' => 'ubuntu_24-04',
        'tld' => 'test',
        'host' => '1.2.3.4',
    ]);

    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'domain' => 'docs.test',
        'php_version' => '8.5',
    ]);

    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);

    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-docs',
        'app_instance_id' => $instance->id,
        'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
    ]);

    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $instance->id,
            'name' => 'frankenphp-docs',
            'runtime' => ProcessRuntime::Docker,
            'sort_order' => 0,
        ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $instance->id,
            'name' => 'vite',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 10,
        ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $instance->id,
            'name' => 'queue',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 20,
        ]);

    app(EnsureFrankenPhpRuntimeProcess::class)->forWorkspace($workspace);

    $otherInstance = AppInstance::factory()->for($app)->create([
        'name' => 'staging',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs-staging',
            document_root: 'public',
            domain: 'staging.docs.test',
        ),
    ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $otherInstance->id,
            'name' => 'worker',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 10,
        ]);

    $payload = app(WorkspaceShowPayload::class)->forWorkspace($workspace);

    expect($payload['inherited_processes'])->toBe([
        ['name' => 'frankenphp-docs-feature-docs'],
        ['name' => 'vite'],
        ['name' => 'queue'],
    ]);
});
