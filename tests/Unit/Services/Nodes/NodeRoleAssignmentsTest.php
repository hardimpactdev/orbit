<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('node role assignments', function (): void {
    it('discovers active app host nodes from composable roles instead of legacy shadows', function (): void {
        $developmentNode = Node::factory()->create(['role' => 'control', 'status' => 'active']);
        $productionNode = Node::factory()->create(['role' => 'control', 'status' => 'active']);
        $legacyAppOnlyNode = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $pendingAppNode = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $databaseNode = Node::factory()->create(['role' => 'control', 'status' => 'active']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $developmentNode->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $productionNode->id,
            'role' => 'app-production',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $pendingAppNode->id,
            'role' => 'app-development',
            'status' => 'pending',
            'settings' => ['tld' => 'test'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $databaseNode->id,
            'role' => 'database',
            'status' => 'active',
        ]);

        $assignments = app(NodeRoleAssignments::class);

        expect($assignments->activeAppHostNodeIds())->toBe([
            $developmentNode->id,
            $productionNode->id,
        ])
            ->and($assignments->activeToolHostNodeIds())->toBe([
                $developmentNode->id,
                $productionNode->id,
                $databaseNode->id,
            ])
            ->and($assignments->nodeHasActiveAppHostRole($developmentNode))->toBeTrue()
            ->and($assignments->nodeHasActiveAppHostRole($productionNode))->toBeTrue()
            ->and($assignments->nodeHasActiveAppHostRole($legacyAppOnlyNode))->toBeFalse()
            ->and($assignments->nodeHasActiveAppHostRole($pendingAppNode))->toBeFalse()
            ->and($assignments->nodeHasActiveAppHostRole($databaseNode))->toBeFalse()
            ->and($assignments->nodeHasActiveToolHostRole($databaseNode))->toBeTrue()
            ->and($assignments->activeAppHostEnvironment($developmentNode))->toBe('development')
            ->and($assignments->activeAppHostEnvironment($productionNode))->toBe('production')
            ->and($assignments->activeAppHostEnvironment($legacyAppOnlyNode))->toBeNull();
    });

    it('only treats active nodes with active gateway assignments as gateways', function (): void {
        $activeLegacyGateway = Node::factory()->create(['role' => 'gateway', 'status' => 'active']);
        $inactiveLegacyGateway = Node::factory()->create(['role' => 'gateway', 'status' => 'provisioning']);
        $activeAssignedGateway = Node::factory()->create(['role' => 'control', 'status' => 'active']);
        $inactiveAssignedGateway = Node::factory()->create(['role' => 'control', 'status' => 'provisioning']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $activeAssignedGateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $inactiveAssignedGateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $assignments = app(NodeRoleAssignments::class);

        expect($assignments->nodeIsGateway($activeLegacyGateway))->toBeFalse()
            ->and($assignments->nodeIsGateway($inactiveLegacyGateway))->toBeFalse()
            ->and($assignments->nodeIsGateway($activeAssignedGateway))->toBeTrue()
            ->and($assignments->nodeIsGateway($inactiveAssignedGateway))->toBeFalse();
    });
});
