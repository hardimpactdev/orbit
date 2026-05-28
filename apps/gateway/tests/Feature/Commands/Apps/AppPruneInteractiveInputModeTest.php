<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $gateway = Node::factory()->gateway()->create([
        'name' => 'gateway',
        'host' => 'gateway',
        'orbit_path' => '/home/gateway/orbit',
        'status' => 'active',
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => $gateway->id,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'document_root' => 'public',
            'agent_ide_config' => json_encode(['adapter' => 'opencode']),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new AppPruneInteractiveTestAdapter);
    app()->instance(RemoteShell::class, new AppPruneInteractiveRemoteShell);
});

it('prompts for missing app name in interactive mode', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('app:prune')
        ->expectsOutputToContain("Removed 1 stale workspace for app 'demo':")
        ->assertExitCode(0);

    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeFalse();
});

it('skips confirmation prompt when --force is present in interactive mode', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $this->artisan('app:prune demo --force')
        ->expectsOutputToContain("Removed 1 stale workspace for app 'demo':")
        ->assertExitCode(0);

    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeFalse();
});

final class AppPruneInteractiveTestAdapter implements AgentIdeMessageAdapter
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

final class AppPruneInteractiveRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
