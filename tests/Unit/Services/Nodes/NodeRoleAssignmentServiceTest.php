<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('node role assignment service', function (): void {
    it('activates a compatible role after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->role)
            ->toBe('database')
            ->and($assignment->converged_at)
            ->not->toBeNull()
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->settings)
            ->toBe([]);
    });

    it('rejects conflicting roles', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-production', []))
            ->toThrow(InvalidArgumentException::class, "Role 'app-production' conflicts with active role 'app-development'.");
    });

    it('marks role as error when convergence fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                throw new RuntimeException('Docker is missing.');
            }
        });

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->last_error)
            ->toBe('Docker is missing.')
            ->and($assignment->converged_at)
            ->toBeNull();
    });

    it('updates an existing role and re-activates it after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'control',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old.test'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new.test']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->settings)
            ->toBe(['tld' => 'new.test'])
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->converged_at)
            ->not->toBeNull();
    });

    it('rejects unsupported platforms', function (): void {
        $node = Node::factory()->create([
            'platform' => 'macos_15',
            'role' => 'control',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' does not support platform 'macos_15'.");
    });

    it('rejects gateway assignment through the normal service', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'gateway', []))
            ->toThrow(InvalidArgumentException::class, "Role 'gateway' cannot be assigned through this service.");
    });

    it('blocks removal when dependents exist and force is false', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        app()->instance(NodeRoleDependencyInspector::class, new class extends NodeRoleDependencyInspector
        {
            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['app api depends on database'];
            }
        });

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'database'))
            ->toThrow(InvalidArgumentException::class, "Role 'database' cannot be removed while dependents exist.");
    });

    it('requires force when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'database', purgeData: true))
            ->toThrow(InvalidArgumentException::class, 'The purgeData option requires force.');
    });

    it('forces removal by clearing dependents and deleting the assignment', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $inspector = new class extends NodeRoleDependencyInspector
        {
            public bool $removedDependents = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['app api depends on database'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                $this->removedDependents = true;
            }
        };

        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        expect($inspector->removedDependents)->toBeTrue()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });
});
