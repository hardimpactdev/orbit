<?php

declare(strict_types=1);

use App\Actions\Apps\PruneAppWorkspaces;
use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'tld' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => 1,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 1,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'document_root' => 'public',
            'agent_ide_config' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    AppInstance::factory()->create([
        'app_id' => 1,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: 1,
            node: 'gateway',
            path: '/home/nckrtl/apps/demo',
            document_root: 'public',
            domain: 'demo.beast',
        ),
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new PruneAppActionTestAdapter);
    app()->instance(RemoteShell::class, new PruneAppWorkspacesActionRemoteShell);
});

it('identifies stale workspaces', function (): void {
    Workspace::create([
        'app_id' => 1,
        'app_instance_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    Workspace::create([
        'app_id' => 1,
        'app_instance_id' => 1,
        'name' => 'active-ws',
        'path' => '/home/nckrtl/apps/demo/active-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        'agent_ide_workspace_id' => 'sess_123',
    ]);

    $app = Project::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app, AppInstance::query()->firstOrFail());

    expect($result['project'])->toBe('demo');
    expect($result['instance'])->toBe('development');
    expect($result['stale_workspaces'])->toHaveCount(1);
    expect($result['stale_workspaces'][0]['name'])->toBe('stale-ws');
    expect($result['stale_workspaces'][0]['removed'])->toBeTrue();
    expect($result['dry_run'])->toBeFalse();
});

it('dry-run does not remove workspaces', function (): void {
    Workspace::create([
        'app_id' => 1,
        'app_instance_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = Project::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app, AppInstance::query()->firstOrFail(), dryRun: true);

    expect($result['dry_run'])->toBeTrue();
    expect($result['stale_workspaces'][0]['removed'])->toBeFalse();
    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeTrue();
});

it('returns empty when no stale workspaces', function (): void {
    Workspace::create([
        'app_id' => 1,
        'app_instance_id' => 1,
        'name' => 'active-ws',
        'path' => '/home/nckrtl/apps/demo/active-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        'agent_ide_workspace_id' => 'sess_123',
    ]);

    $app = Project::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app, AppInstance::query()->firstOrFail());

    expect($result['stale_workspaces'])->toBe([]);
});

it('throws when no adapter configured', function (): void {
    AppInstance::query()->update(['agent_ide_config' => null]);

    $app = Project::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);

    expect(fn () => $prune->handle($app, AppInstance::query()->firstOrFail()))
        ->toThrow(RuntimeException::class, 'No agent IDE adapter configured for this instance.');
});

it('prunes using explicit adapter name', function (): void {
    Workspace::create([
        'app_id' => 1,
        'app_instance_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = Project::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app, AppInstance::query()->firstOrFail(), adapterName: 'opencode');

    expect($result['project'])->toBe('demo');
    expect($result['instance'])->toBe('development');
    expect($result['stale_workspaces'])->toHaveCount(1);
    expect($result['stale_workspaces'][0]['name'])->toBe('stale-ws');
    expect($result['stale_workspaces'][0]['removed'])->toBeTrue();
});

final class PruneAppWorkspacesActionRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
