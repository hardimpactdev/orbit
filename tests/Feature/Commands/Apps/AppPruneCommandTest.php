<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'ssh_user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 1,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'environment' => 'development',
            'document_root' => 'public',
            'agent_ide_config' => json_encode(['adapter' => 'opencode']),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new AppPruneTestAdapter);
});

it('prunes stale workspaces for a gateway caller', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    Workspace::create([
        'app_id' => 1,
        'name' => 'active-ws',
        'path' => '/home/nckrtl/apps/demo/active-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        'agent_ide_workspace_id' => 'sess_123',
    ]);

    $exitCode = Artisan::call('app:prune', [
        'app' => 'demo',
        '--force' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['app'])->toBe('demo');
    expect($payload['success']['data']['stale_workspaces'])->toHaveCount(1);
    expect($payload['success']['data']['stale_workspaces'][0]['name'])->toBe('stale-ws');
    expect($payload['success']['data']['stale_workspaces'][0]['removed'])->toBeTrue();
    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeFalse();
    expect(Workspace::query()->where('name', 'active-ws')->exists())->toBeTrue();
});

it('dry-run previews stale workspaces without removing', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $exitCode = Artisan::call('app:prune', [
        'app' => 'demo',
        '--dry-run' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['dry_run'])->toBeTrue();
    expect($payload['success']['data']['stale_workspaces'][0]['removed'])->toBeFalse();
    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeTrue();
});

it('rejects app-node callers', function (): void {
    DB::table('nodes')->update(['is_local' => false]);
    DB::table('nodes')->insert([
        [
            'name' => 'beast',
            'role' => 'app',
            'host' => 'beast',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $exitCode = Artisan::call('app:prune', [
        'app' => 'demo',
        '--force' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('caller_role_not_allowed');
});

it('requires force in non-interactive mode', function (): void {
    $exitCode = Artisan::call('app:prune', [
        'app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('force');
});

it('reports app not found', function (): void {
    $exitCode = Artisan::call('app:prune', [
        'app' => 'missing',
        '--force' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('app.not_found');
});

it('reports no adapter configured', function (): void {
    App::query()->update(['agent_ide_config' => null]);

    $exitCode = Artisan::call('app:prune', [
        'app' => 'demo',
        '--force' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('app.no_agent_ide_adapter');
});

it('reports no stale workspaces when all are active', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'active-ws',
        'path' => '/home/nckrtl/apps/demo/active-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        'agent_ide_workspace_id' => 'sess_123',
    ]);

    $exitCode = Artisan::call('app:prune', [
        'app' => 'demo',
        '--force' => true,
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['stale_workspaces'])->toBe([]);
});

it('renders human output without json', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $this->artisan('app:prune', [
        'app' => 'demo',
        '--force' => true,
    ])
        ->expectsOutputToContain("Removed 1 stale workspace for app 'demo':")
        ->expectsOutputToContain('stale-ws')
        ->assertSuccessful();
});

final class AppPruneTestAdapter implements AgentIdeMessageAdapter
{
    public function activeSession(array $target, string $adapter): ?array
    {
        return null;
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        return ['status' => 'failed'];
    }

    public function workspaces(array $target, string $adapter): array
    {
        return ['active-ws'];
    }
}
