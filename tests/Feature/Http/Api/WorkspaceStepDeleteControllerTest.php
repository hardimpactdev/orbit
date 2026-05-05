<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_STEP_DELETE_CALLER_WG_IP = '10.6.0.97';

function createWorkspaceStepDeleteCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'step-delete-caller',
        'role' => 'control',
        'host' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
    ], $overrides));
}

function grantWorkspaceStepDeleteAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceStepDeleteController', function (): void {
    it('deletes a workspace step for authorized callers and compacts order', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $node = Node::factory()->create(['role' => 'app']);
        grantWorkspaceStepDeleteAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup, 'sort_order' => 1]);
        $removed = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup, 'sort_order' => 2, 'command' => 'npm install']);
        WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Setup, 'sort_order' => 3]);

        $response = $this->call('DELETE', "/api/workspaces/steps/setup/{$removed->id}", ['app' => 'docs'], [], [], [
            'REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'removed')
            ->assertJsonPath('success.data.step.id', $removed->id)
            ->assertJsonPath('success.data.step.command', 'npm install')
            ->assertJsonPath('success.meta.remaining_step_count', 2);

        expect(WorkspaceStep::query()->whereKey($removed->id)->exists())->toBeFalse()
            ->and(WorkspaceStep::query()->where('app_id', $app->id)->where('phase', WorkspaceLifecyclePhase::Setup)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);
    });

    it('rejects app-node callers', function (): void {
        createWorkspaceStepDeleteCallerNode(['role' => 'app']);

        $response = $this->call('DELETE', '/api/workspaces/steps/setup/12', ['app' => 'docs'], [], [], [
            'REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed');
    });

    it('returns phase-scoped step-not-found errors', function (): void {
        createWorkspaceStepDeleteCallerNode(['role' => 'gateway']);
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $step = WorkspaceStep::factory()->create(['app_id' => $app->id, 'phase' => WorkspaceLifecyclePhase::Teardown]);

        $response = $this->call('DELETE', "/api/workspaces/steps/setup/{$step->id}", ['app' => 'docs'], [], [], [
            'REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found')
            ->assertJsonPath('error.meta.phase', 'setup');
    });
});
