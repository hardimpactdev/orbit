<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\Node;
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
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(RemoteShell::class, new WorkspaceNewTestShell);
});

it('creates a workspace for a gateway caller', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace']['name'])->toBe('feature-a');
    expect($payload['success']['data']['workspace']['app'])->toBe('demo');
    expect($payload['success']['data']['result'])->toBe(['action' => 'created']);
    expect($payload['success']['data']['workspace']['path'])->toBe('/home/nckrtl/apps/demo/feature-a');
    expect($payload['success']['data']['workspace']['url'])->toBe('https://feature-a.demo.beast');
    expect($payload['success']['data']['workspace']['lifecycle_status'])->toBe('active');
    expect($payload['success']['meta']['base'])->toBe('main');

    $workspace = Workspace::query()
        ->where('name', 'feature-a')
        ->where('app_id', 1)
        ->first();

    expect($workspace)->not->toBeNull();
    expect($workspace->path)->toBe('/home/nckrtl/apps/demo/feature-a');
});

it('runs remote worktree provisioning before setup', function (): void {
    $shell = new WorkspaceNewTestShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--base' => 'feature/source',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(implode("\n---\n", $shell->scripts))
        ->toContain('git -C "$app_path" worktree add --detach "$workspace_path" "$base_ref"')
        ->toContain("base_ref='feature/source'");
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

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('caller_role_not_allowed');
});

it('rejects reserved name main', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'main',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('name');
});

it('rejects invalid workspace names', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'Feature_A',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
});

it('rejects duplicate workspace names per app', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
    ]);

    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('workspace.already_exists');
});

it('creates workspace with supported custom php version', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-php',
        '--app' => 'demo',
        '--php-version' => '8.5',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace']['php_version'])->toBe('8.5');

    $workspace = Workspace::query()
        ->where('name', 'feature-php')
        ->first();

    expect($workspace->php_version)->toBe('8.5');
});

it('rejects unsupported php versions', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        'name' => 'feature-php',
        '--app' => 'demo',
        '--php-version' => '8.4',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('php_version');
});

it('renders human output without json', function (): void {
    $this->artisan('workspace:new', [
        'name' => 'feature-human',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain("Workspace 'feature-human' created for app 'demo'.")
        ->expectsOutputToContain('/home/nckrtl/apps/demo/feature-human')
        ->assertSuccessful();
});

it('requires name in non-interactive mode', function (): void {
    $exitCode = Artisan::call('workspace:new', [
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('name');
});

final class WorkspaceNewTestShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
