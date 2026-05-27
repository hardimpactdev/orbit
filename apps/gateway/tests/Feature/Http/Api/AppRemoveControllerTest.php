<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_REMOVE_CALLER_WG_IP = '10.6.0.80';

function createAppRemoveCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_REMOVE_CALLER_WG_IP,
        'wireguard_address' => APP_REMOVE_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRemoveAccess(Node $caller, Node $appNode, array $permissions = ['app:remove']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppRemoveController', function (): void {
    it('removes app intent for authorized callers', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', 64),
        ]);

        app()->instance(RemoteShell::class, new AppRemoveApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('DELETE', '/api/apps/docs', [
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.result.action', 'removed')
            ->assertJsonPath('success.data.cleanup.proxy_routes_removed', 1);

        expect(App::query()->where('name', 'docs')->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse();
    });

    it('requires destructive consent before removing app intent', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);

        app()->instance(RemoteShell::class, new AppRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        expect(App::query()->whereKey($app->id)->exists())->toBeTrue();
    });

    it('rejects app removal when the caller lacks app:remove on the app node', function (): void {
        $caller = createAppRemoveCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);
        grantAppRemoveAccess($caller, $targetNode, ['app:read']);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);

        app()->instance(RemoteShell::class, new AppRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/apps/docs', [
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => APP_REMOVE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:remove')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue();
    });
});

final class AppRemoveApiSequencedRemoteShell implements RemoteShell
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
