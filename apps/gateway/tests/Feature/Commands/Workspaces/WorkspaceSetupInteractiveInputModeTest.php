<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);

    app()->instance(RemoteShell::class, new WorkspaceSetupInteractiveRemoteShell);
});

it('prompts for missing --app in interactive mode when name and app are both absent and cwd resolves the workspace', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'tld' => 'test',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
    ]);

    $cwd = sys_get_temp_dir().'/orbit-ws-setup-'.bin2hex(random_bytes(4));
    mkdir($cwd);

    Workspace::factory()->create([
        'name' => 'feature-api',
        'app_id' => App::query()->where('name', 'docs')->value('id'),
        'path' => $cwd,
    ]);

    $previous = getcwd();
    chdir($cwd);

    try {
        // App option missing and name missing → prompt fires; CWD resolves workspace anyway → success
        DataTablePrompt::fake([Key::ENTER]);

        $this->artisan('workspace:setup')
            ->assertSuccessful();
    } finally {
        chdir((string) $previous);
        rmdir($cwd);
    }
});

it('does not prompt for --app when it is supplied', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'tld' => 'test',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
    ]);

    Workspace::factory()->create([
        'name' => 'feature-api',
        'app_id' => App::query()->where('name', 'docs')->value('id'),
        'path' => '/srv/docs/.worktrees/feature-api',
    ]);

    $exitCode = Artisan::call('workspace:setup', [
        'name' => 'feature-api',
        '--app' => 'docs',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
});

it('does not prompt when --json is set and fails instead', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'tld' => 'test',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
    ]);

    // --app not supplied, --json set, no CWD match → resolver fails (no prompt fired)
    $exitCode = Artisan::call('workspace:setup', [
        'name' => 'feature-api',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(isset($payload['error']['code']))->toBeTrue();
});

final class WorkspaceSetupInteractiveRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
