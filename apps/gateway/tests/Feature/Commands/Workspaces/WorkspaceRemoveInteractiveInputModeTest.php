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

    app()->instance(RemoteShell::class, new WorkspaceRemoveInteractiveRemoteShell);
});

it('prompts for missing workspace name in interactive mode', function (): void {
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

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
    ]);

    Workspace::factory()->create([
        'name' => 'feature-api',
        'app_id' => $app->id,
        'path' => '/srv/docs/.worktrees/feature-api',
    ]);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('workspace:remove --force')
        ->expectsOutputToContain('feature-api')
        ->assertSuccessful();
});

it('does not prompt when workspace name is supplied', function (): void {
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

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
    ]);

    Workspace::factory()->create([
        'name' => 'feature-api',
        'app_id' => $app->id,
        'path' => '/srv/docs/.worktrees/feature-api',
    ]);

    $exitCode = Artisan::call('workspace:remove', [
        'name' => 'feature-api',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['name'])->toBe('feature-api');
});

it('fails non-interactively when workspace name is missing and --json is set', function (): void {
    $exitCode = Artisan::call('workspace:remove', [
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

final class WorkspaceRemoveInteractiveRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
