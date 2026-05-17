<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_SHOW_CALLER_WG_IP = '10.6.0.98';

function createAppShowCallerNode(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_SHOW_CALLER_WG_IP,
        'wireguard_address' => APP_SHOW_CALLER_WG_IP,
    ], $overrides);

    if ($attributes['role'] === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

function grantAppShowAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppShowController', function (): void {
    it('returns app registry details by name', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        grantAppShowAccess($caller, $node);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'environment' => 'production',
            'domain' => 'docs.example.com',
            'path' => '/srv/docs',
            'document_root' => 'public',
            'repository' => 'git@github.com:orbit/docs.git',
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.data.app.url', 'https://docs.example.com')
            ->assertJsonPath('success.data.details.domain', 'docs.example.com')
            ->assertJsonPath('success.data.details.document_root', '/srv/docs/public')
            ->assertJsonPath('success.data.details.node.name', 'app-1')
            ->assertJsonPath('success.data.details.node.host', '10.6.0.7')
            ->assertJsonPath('success.data.details.workspaces', [])
            ->assertJsonPath('success.data.details.processes', [])
            ->assertJsonPath('success.data.details.routes.0.host', 'docs.example.com');
    });

    it('resolves by hostname when no app name matches', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['role' => 'app']);
        grantAppShowAccess($caller, $node);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.example.com']);

        $response = $this->call('GET', '/api/apps/docs.example.com', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs');
    });

    it('prefers app name over hostname collisions', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['role' => 'app']);
        grantAppShowAccess($caller, $node);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.example.com']);
        App::factory()->create(['name' => 'docs.example.com', 'node_id' => $node->id, 'domain' => 'other.example.com']);

        $response = $this->call('GET', '/api/apps/docs.example.com', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs.example.com');
    });

    it('returns not found for hidden apps', function (): void {
        createAppShowCallerNode();
        $node = createTestAppHostNode(['role' => 'app']);
        App::factory()->create(['name' => 'hidden', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps/hidden', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found')
            ->assertJsonPath('error.message', "App 'hidden' not found or not visible.");
    });

    it('lets gateway callers inspect any app', function (): void {
        createAppShowCallerNode(['role' => 'gateway']);
        $node = createTestAppHostNode(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/apps/docs');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
