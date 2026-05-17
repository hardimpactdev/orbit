<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
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

    it('materializes docker as a desired tool for database roles', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'docker')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('running');
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
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);

        App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-development'))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' cannot be removed while dependents exist.");
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
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true);

        expect(App::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('forces database role removal by clearing database tool dependents and docker baseline intent', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'running',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['postgres', 'docker'])->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('leaves a removing assignment in error when cleanup fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('Cleanup failed.');
            }
        });

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        expect($assignment->fresh()->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->fresh()->last_error)
            ->toBe('Cleanup failed.');
    });
});
