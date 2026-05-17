<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_STEP_STORE_CALLER_WG_IP = '10.6.0.98';

function createWorkspaceStepStoreCallerNode(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'step-store-caller',
        'role' => 'control',
        'host' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
    ], $overrides);

    return match ($attributes['role']) {
        'app' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantWorkspaceStepStoreAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceStepStoreController', function (): void {
    it('creates a workspace step for authorized callers', function (): void {
        $caller = createWorkspaceStepStoreCallerNode();
        $node = createTestAppHostNode(['role' => 'app']);
        grantWorkspaceStepStoreAccess($caller, $node);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('POST', '/api/workspaces/steps/setup', [], [], [], [
            'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'app' => 'docs',
            'command' => 'composer install',
            'timeout' => 600,
        ], JSON_THROW_ON_ERROR));

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'added')
            ->assertJsonPath('success.data.step.app', 'docs')
            ->assertJsonPath('success.data.step.phase', 'setup')
            ->assertJsonPath('success.data.step.order', 1);
    });

    it('rejects app-node callers', function (): void {
        createWorkspaceStepStoreCallerNode(['role' => 'app']);

        $response = $this->call('POST', '/api/workspaces/steps/setup', [], [], [], [
            'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['app' => 'docs', 'command' => 'composer install'], JSON_THROW_ON_ERROR));

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed');
    });

    it('validates bad timeout and unknown anchors', function (): void {
        $caller = createWorkspaceStepStoreCallerNode(['role' => 'gateway']);
        $node = createTestAppHostNode(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Teardown]);

        $timeout = $this->call('POST', '/api/workspaces/steps/setup', [], [], [], [
            'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['app' => 'docs', 'command' => 'composer install', 'timeout' => 0], JSON_THROW_ON_ERROR));
        $anchor = $this->call('POST', '/api/workspaces/steps/setup', [], [], [], [
            'REMOTE_ADDR' => $caller->wireguard_address,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['app' => 'docs', 'command' => 'composer install', 'before' => 999], JSON_THROW_ON_ERROR));

        $timeout->assertStatus(400)
            ->assertJsonPath('error.meta.field', 'timeout');
        $anchor->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found');
    });
});
