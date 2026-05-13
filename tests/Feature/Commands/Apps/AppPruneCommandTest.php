<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Http\Gateway\Requests\Apps\PruneAppRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
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
    app()->instance(RemoteShell::class, new AppPruneCommandRemoteShell);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
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

it('forwards app-node CLI callers through the typed gateway request', function (): void {
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        PruneAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => 'demo',
                    'stale_workspaces' => [],
                    'dry_run' => false,
                ],
                'meta' => [],
            ],
        ], 200),
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

final class AppPruneCommandRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
