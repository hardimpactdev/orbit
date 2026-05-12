<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_STEP_LIST_CALLER_WG_IP = '10.6.0.97';

function createWorkspaceStepListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'step-list-caller',
        'role' => 'control',
        'host' => WORKSPACE_STEP_LIST_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_STEP_LIST_CALLER_WG_IP,
    ], $overrides));
}

function grantWorkspaceStepListAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceStepListController', function (): void {
    it('returns visible setup steps sorted by order', function (): void {
        $caller = createWorkspaceStepListCallerNode();
        $node = Node::factory()->create(['role' => 'app']);
        grantWorkspaceStepListAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 2,
            'command' => 'npm install',
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
        ]);

        $response = $this->call('GET', '/api/workspaces/steps/setup?app=docs', [], [], [], ['REMOTE_ADDR' => WORKSPACE_STEP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.steps.0.command', 'composer install')
            ->assertJsonPath('success.data.steps.0.app', 'docs')
            ->assertJsonPath('success.data.steps.0.phase', 'setup');
    });

    it('resolves visible apps by path', function (): void {
        $caller = createWorkspaceStepListCallerNode();
        $node = Node::factory()->create(['role' => 'app']);
        grantWorkspaceStepListAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
        Workspace::factory()->create(['app_id' => $app->id, 'path' => '/srv/docs/.worktrees/feature-docs']);
        WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Teardown, 'command' => 'dropdb docs']);

        $response = $this->call('GET', '/api/workspaces/steps/teardown?path=/srv/docs/.worktrees/feature-docs/app', [], [], [], ['REMOTE_ADDR' => WORKSPACE_STEP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.steps.0.command', 'dropdb docs');
    });

    it('returns app not found for unknown apps', function (): void {
        createWorkspaceStepListCallerNode(['role' => 'gateway']);

        $response = $this->call('GET', '/api/workspaces/steps/setup?app=missing', [], [], [], ['REMOTE_ADDR' => WORKSPACE_STEP_LIST_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.app_not_found')
            ->assertJsonPath('error.meta.app', 'missing');
    });

    it('returns authorization failures for hidden apps', function (): void {
        createWorkspaceStepListCallerNode();
        $node = Node::factory()->create(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/workspaces/steps/setup?app=docs', [], [], [], ['REMOTE_ADDR' => WORKSPACE_STEP_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.app', 'docs');
    });
});
