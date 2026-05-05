<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_LIST_CALLER_WG_IP = '10.6.0.99';

function createAppListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_LIST_CALLER_WG_IP,
        'wireguard_address' => APP_LIST_CALLER_WG_IP,
    ], $overrides));
}

function grantAppListAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppListController', function (): void {
    it('lists visible apps sorted by owning node then app name', function (): void {
        $caller = createAppListCallerNode();
        $zNode = Node::factory()->create(['name' => 'z-node', 'role' => 'app']);
        $aNode = Node::factory()->create(['name' => 'a-node', 'role' => 'app']);
        grantAppListAccess($caller, $zNode);
        grantAppListAccess($caller, $aNode);

        App::factory()->create(['name' => 'zebra', 'node_id' => $zNode->id, 'domain' => 'zebra.test']);
        App::factory()->create(['name' => 'beta', 'node_id' => $aNode->id, 'domain' => 'beta.test']);
        App::factory()->create(['name' => 'alpha', 'node_id' => $aNode->id, 'domain' => 'alpha.test']);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk();

        $apps = $response->json('success.data.apps');
        expect(array_column($apps, 'name'))->toBe(['alpha', 'beta', 'zebra']);
    });

    it('filters apps by owning node and environment', function (): void {
        $caller = createAppListCallerNode();
        $devNode = Node::factory()->create(['name' => 'dev-1', 'role' => 'app']);
        $prodNode = Node::factory()->create(['name' => 'prod-1', 'role' => 'app']);
        grantAppListAccess($caller, $devNode);
        grantAppListAccess($caller, $prodNode);

        App::factory()->create(['name' => 'docs', 'node_id' => $devNode->id, 'environment' => 'development']);
        App::factory()->create(['name' => 'site', 'node_id' => $prodNode->id, 'environment' => 'production']);

        $response = $this->call('GET', '/api/apps?node=prod-1&environment=production', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.apps')
            ->assertJsonPath('success.data.apps.0.name', 'site');
    });

    it('omits hidden apps from the result', function (): void {
        $caller = createAppListCallerNode();
        $visibleNode = Node::factory()->create(['name' => 'visible-node', 'role' => 'app']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node', 'role' => 'app']);
        grantAppListAccess($caller, $visibleNode);

        App::factory()->create(['name' => 'visible', 'node_id' => $visibleNode->id]);
        App::factory()->create(['name' => 'hidden', 'node_id' => $hiddenNode->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.apps')
            ->assertJsonPath('success.data.apps.0.name', 'visible');
    });

    it('lets gateway callers read all app registry records', function (): void {
        createAppListCallerNode(['role' => 'gateway']);
        $firstNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $secondNode = Node::factory()->create(['name' => 'app-2', 'role' => 'app']);

        App::factory()->create(['name' => 'first', 'node_id' => $firstNode->id]);
        App::factory()->create(['name' => 'second', 'node_id' => $secondNode->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.apps');
    });

    it('returns authorization failure when the caller has no app registry visibility', function (): void {
        createAppListCallerNode();
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to read the app registry.');
    });

    it('returns validation error for invalid environment', function (): void {
        createAppListCallerNode(['role' => 'gateway']);

        $response = $this->call('GET', '/api/apps?environment=staging', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'environment')
            ->assertJsonPath('error.meta.allowed', ['development', 'production']);
    });

    it('returns the canonical app entity shape', function (): void {
        createAppListCallerNode(['role' => 'gateway']);
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'environment' => 'development',
            'domain' => null,
            'path' => '/srv/docs',
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.apps.0', [
                'name' => 'docs',
                'node' => 'app-1',
                'environment' => 'development',
                'url' => 'https://docs.test',
                'path' => '/srv/docs',
                'root' => 'public',
                'repository' => null,
                'php_version' => '8.5',
                'adopted' => false,
            ]);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/apps');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
