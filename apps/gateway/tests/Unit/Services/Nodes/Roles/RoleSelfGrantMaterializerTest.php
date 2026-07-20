<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\RoleSelfGrantMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function roleSelfGrantNode(): Node
{
    return Node::factory()->create([
        'platform' => 'ubuntu',
    ]);
}

function roleSelfGrantAssign(Node $node, NodeRoleName $role): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role->value,
        'status' => NodeRoleStatus::Active->value,
        'settings' => match ($role) {
            NodeRoleName::AppDevelopment, NodeRoleName::Agent => ['tld' => 'test'],
            default => [],
        },
    ]);
}

function roleSelfGrant(Node $node): ?NodeAccess
{
    return NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $node->id)
        ->first();
}

describe('RoleSelfGrantMaterializer', function (): void {
    it('projects effective self permissions without side effects', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::AppDevelopment);

        $permissions = app(RoleSelfGrantMaterializer::class)->effectiveSelfPermissions($node);

        expect($permissions)
            ->toBe([
                'instance:read',
                'instance:register',
                'process:add',
                'process:read',
                'process:remove',
                'process:update',
                'project:read',
                'workspace:setup',
            ])
            ->and(roleSelfGrant($node))
            ->toBeNull();
    });

    it('materializes the union of active role self presets', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::Agent);
        roleSelfGrantAssign($node, NodeRoleName::AppDevelopment);

        app(RoleSelfGrantMaterializer::class)->materializeOnRoleApplied($node, NodeRoleName::Agent);

        $grant = roleSelfGrant($node);

        expect($grant)
            ->not
            ->toBeNull()
            ->and($grant->permissions)
            ->toBe([
                'doctor:verify',
                'instance:read',
                'instance:register',
                'node:read',
                'process:add',
                'process:read',
                'process:remove',
                'process:update',
                'project:read',
                'tool:read',
                'tool:update:agent-tools',
                'workspace:setup',
            ])
            ->and($grant->custom_permissions)
            ->toBe([]);
    });

    it('keeps overlapping role-derived permissions until the last source role is removed', function (): void {
        $node = roleSelfGrantNode();
        $development = roleSelfGrantAssign($node, NodeRoleName::AppDevelopment);
        $production = roleSelfGrantAssign($node, NodeRoleName::AppProduction);

        app(RoleSelfGrantMaterializer::class)->materializeOnRoleApplied($node, NodeRoleName::AppDevelopment);

        $development->delete();
        app(RoleSelfGrantMaterializer::class)->reconcileOnRoleRemoved($node, NodeRoleName::AppDevelopment);

        expect(roleSelfGrant($node)?->permissions)->toBe(['instance:read', 'project:read']);

        $production->delete();
        app(RoleSelfGrantMaterializer::class)->reconcileOnRoleRemoved($node, NodeRoleName::AppProduction);

        expect(roleSelfGrant($node))->toBeNull();
    });

    it('does not materialize workspace permissions while app production is active', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::AppDevelopment);
        roleSelfGrantAssign($node, NodeRoleName::AppProduction);

        app(RoleSelfGrantMaterializer::class)->materializeOnRoleApplied($node, NodeRoleName::AppProduction);

        expect(roleSelfGrant($node)?->permissions)
            ->toBe([
                'instance:read',
                'instance:register',
                'process:add',
                'process:read',
                'process:remove',
                'process:update',
                'project:read',
            ]);
    });

    it('sanitizes every grant edge when app production becomes active', function (): void {
        $productionNode = roleSelfGrantNode();
        $consumer = Node::factory()->create();
        $serving = Node::factory()->create();
        roleSelfGrantAssign($productionNode, NodeRoleName::AppProduction);
        $incoming = NodeAccess::query()->create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $productionNode->id,
            'permissions' => ['instance:read', 'workspace:read'],
            'custom_permissions' => ['workspace:read'],
        ]);
        $outgoing = NodeAccess::query()->create([
            'consumer_node_id' => $productionNode->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['*'],
            'custom_permissions' => ['*'],
        ]);

        app(RoleSelfGrantMaterializer::class)->materializeOnRoleApplied(
            $productionNode,
            NodeRoleName::AppProduction,
        );

        expect($incoming->fresh()?->permissions)
            ->toBe(['instance:read'])
            ->and($incoming->fresh()?->custom_permissions)
            ->toBe([])
            ->and($outgoing->fresh()?->permissions)
            ->not->toContain(
                '*',
                'workspace:*',
                'workspace:read',
                'workspace:setup',
            )->and($outgoing->fresh()?->custom_permissions)
            ->not->toContain('*', 'workspace:*', 'workspace:read', 'workspace:setup');
    });

    it('preserves custom additions while dropping permissions exclusive to a removed role', function (): void {
        $node = roleSelfGrantNode();
        $assignment = roleSelfGrantAssign($node, NodeRoleName::AppDevelopment);

        NodeAccess::query()->create([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
            'permissions' => ['tool:read'],
            'custom_permissions' => ['tool:read'],
        ]);

        app(RoleSelfGrantMaterializer::class)->materializeOnRoleApplied($node, NodeRoleName::AppDevelopment);

        expect(roleSelfGrant($node)?->permissions)
            ->toBe([
                'instance:read',
                'instance:register',
                'process:add',
                'process:read',
                'process:remove',
                'process:update',
                'project:read',
                'tool:read',
                'workspace:setup',
            ])
            ->and(roleSelfGrant($node)?->custom_permissions)
            ->toBe(['tool:read']);

        $assignment->delete();
        app(RoleSelfGrantMaterializer::class)->reconcileOnRoleRemoved($node, NodeRoleName::AppDevelopment);

        expect(roleSelfGrant($node)?->permissions)
            ->toBe(['tool:read'])
            ->and(roleSelfGrant($node)?->custom_permissions)
            ->toBe(['tool:read']);
    });

    it('rematerializes migrated custom permissions without exposing or rejecting rollback tokens', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::Agent);

        NodeAccess::query()->create([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
            'permissions' => ['app:read', 'project:read', 'instance:read'],
            'custom_permissions' => ['app:read', 'project:read', 'instance:read'],
        ]);

        $materializer = app(RoleSelfGrantMaterializer::class);

        expect($materializer->effectiveSelfPermissions($node))
            ->toContain('project:read', 'instance:read');

        $materializer->materializeOnRoleApplied($node, NodeRoleName::Agent);

        expect(roleSelfGrant($node)?->permissions)
            ->toContain('app:read', 'project:read', 'instance:read')
            ->and(roleSelfGrant($node)?->custom_permissions)
            ->toContain('app:read', 'project:read', 'instance:read');
    });

    it('supports node new custom self-grant override before later rematerialization', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::Agent);
        $materializer = app(RoleSelfGrantMaterializer::class);

        $materializer->materializeOnRoleApplied($node, NodeRoleName::Agent);
        $materializer->replaceCustomSelfPermissions($node, ['node:read', 'tool:read']);

        expect(roleSelfGrant($node)?->permissions)
            ->toBe(['node:read', 'tool:read'])
            ->and(roleSelfGrant($node)?->custom_permissions)
            ->toBe(['node:read', 'tool:read']);

        $materializer->materializeOnRoleApplied($node, NodeRoleName::Agent);

        expect(roleSelfGrant($node)?->permissions)
            ->toBe([
                'doctor:verify',
                'node:read',
                'tool:read',
                'tool:update:agent-tools',
            ])
            ->and(roleSelfGrant($node)?->custom_permissions)
            ->toBe(['node:read', 'tool:read']);
    });

    it('restores active role-derived permissions after attempted removal', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::AppDevelopment);
        $materializer = app(RoleSelfGrantMaterializer::class);

        $materializer->materializeOnRoleApplied($node, NodeRoleName::AppDevelopment);

        roleSelfGrant($node)?->update([
            'permissions' => [],
            'custom_permissions' => [],
        ]);

        $materializer->materializeOnRoleApplied($node, NodeRoleName::AppDevelopment);

        expect(roleSelfGrant($node)?->permissions)->toBe([
            'instance:read',
            'instance:register',
            'process:add',
            'process:read',
            'process:remove',
            'process:update',
            'project:read',
            'workspace:setup',
        ]);
    });

    it('does not create self grants for active roles with empty self presets', function (): void {
        $node = roleSelfGrantNode();
        roleSelfGrantAssign($node, NodeRoleName::Database);

        app(RoleSelfGrantMaterializer::class)->materializeOnRoleApplied($node, NodeRoleName::Database);

        expect(roleSelfGrant($node))->toBeNull();
    });
});
