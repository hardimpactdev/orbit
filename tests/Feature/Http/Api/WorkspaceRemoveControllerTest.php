<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_REMOVE_CALLER_WG_IP = '10.6.0.81';

function createWorkspaceRemoveCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => WORKSPACE_REMOVE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_REMOVE_CALLER_WG_IP,
    ], $overrides));
}

function grantWorkspaceRemoveAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceRemoveController', function (): void {
    it('removes workspace intent for authorized callers', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-api',
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'feature-api.docs.test',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?app=docs',
            [
                'keep_files' => false,
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.name', 'feature-api')
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.proxy_routes_removed', 1)
            ->assertJsonPath('success.meta.kept_files', false);

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'feature-api.docs.test')->exists())->toBeFalse();
    });

    it('requires destructive consent before removing workspace intent', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-api',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/workspaces/feature-api?app=docs', [], [], [], ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });

    it('rejects workspace removal when the caller cannot access the app node', function (): void {
        createWorkspaceRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-api',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/workspaces/feature-api?app=docs', [
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });
});

final class WorkspaceRemoveApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
