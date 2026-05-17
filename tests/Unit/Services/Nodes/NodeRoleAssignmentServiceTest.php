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
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
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

    it('materializes the production app runtime baseline as desired tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'host' => 'app-prod-1.example.com',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-production', []);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'php', 'supervisor'])
            ->orderBy('name')
            ->get();

        expect($tools->pluck('name')->all())
            ->toBe(['caddy', 'php', 'supervisor'])
            ->and($tools->pluck('expected_state')->unique()->all())
            ->toBe(['running']);
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

    it('ignores non-active assignments during conflict checks', function (string $status): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => $status,
            'settings' => ['tld' => 'test'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-production', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->role)
            ->toBe('app-production');
    })->with([
        NodeRoleStatus::Pending->value,
        NodeRoleStatus::Error->value,
        NodeRoleStatus::Removing->value,
    ]);

    it('marks role as error when convergence fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                );
            }

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

    it('marks app-development as error when the development dns mapping cannot be materialized', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'wireguard_address' => null,
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->last_error)
            ->toBe('The app-development role requires a WireGuard address so the development DNS mapping can be materialized.')
            ->and($assignment->converged_at)
            ->toBeNull();
    });

    it('rejects production and database baselines for nodes with an assigned gateway role', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'host' => 'gateway.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $productionAssignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => 'app-production',
            'status' => NodeRoleStatus::Pending->value,
        ]);
        $databaseAssignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Pending->value,
        ]);

        expect(fn () => app(AppProductionRoleBaseline::class)->converge($node, $productionAssignment))
            ->toThrow(RuntimeException::class, 'The app-production role cannot be assigned to a gateway node.');

        expect(fn () => app(DatabaseRoleBaseline::class)->converge($node, $databaseAssignment))
            ->toThrow(RuntimeException::class, 'The database role cannot be assigned to a gateway node.');
    });

    it('updates an existing role and re-activates it after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'control',
            'wireguard_address' => '10.0.0.10',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->settings)
            ->toBe(['tld' => 'new'])
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->converged_at)
            ->not->toBeNull();
    });

    it('rejects updates when a conflicting role is active', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'control',
            'wireguard_address' => '10.0.0.10',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-production',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' conflicts with active role 'app-production'.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->fresh()->last_error)->toBeNull();
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

    it('rejects gateway updates through the normal service', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'gateway', []))
            ->toThrow(InvalidArgumentException::class, "Role 'gateway' cannot be updated through this service.");
    });

    it('rejects unknown roles during removal through the registry', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'queue'))
            ->toThrow(InvalidArgumentException::class, 'Unknown node role [queue].');
    });

    it('rejects gateway removal through the normal service', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'gateway'))
            ->toThrow(InvalidArgumentException::class, "Role 'gateway' cannot be removed through this service.");
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

    it('rechecks removal dependents inside the transaction before destructive cleanup', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);
        $inspector = new class extends NodeRoleDependencyInspector
        {
            public int $calls = 0;

            public bool $removed = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                $this->calls++;

                return $this->calls === 1 ? [] : ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->removed = true;
            }
        };
        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-development'))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' cannot be removed while dependents exist.");

        expect($assignment->fresh()->status)->toBe(NodeRoleStatus::Active->value)
            ->and($inspector->calls)->toBe(2)
            ->and($inspector->removed)->toBeFalse();
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

    it('forces removal by deleting Orbit-owned dependents and deleting the assignment', function (): void {
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

    it('removes app dependents and passes purge intent when purge data is requested', function (): void {
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

        app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true, purgeData: true);

        expect(App::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('forces database role removal by deleting database dependents and clearing docker baseline intent', function (): void {
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

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'docker')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('removes database dependents and passes purge intent when purge data is requested', function (): void {
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

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true, purgeData: true);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['postgres', 'docker'])->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('leaves the assignment in error and keeps dependents intact when baseline removal fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);
        $inspector = new class extends NodeRoleDependencyInspector
        {
            public bool $removed = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->removed = true;
            }
        };
        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                );
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('Cleanup failed.');
            }
        });

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true))
            ->toThrow(RuntimeException::class, 'Cleanup failed.');

        expect($assignment->fresh()->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->fresh()->last_error)
            ->toBe('Cleanup failed.')
            ->and($inspector->removed)
            ->toBeFalse()
            ->and(App::query()->whereKey($app->id)->exists())
            ->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())
            ->toBeTrue();
    });
});
