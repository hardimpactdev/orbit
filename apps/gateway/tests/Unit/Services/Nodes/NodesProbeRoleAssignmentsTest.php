<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Workspace;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $remoteShell = new NodesProbeRoleAssignmentsRemoteShell;

    $this->app->instance(RemoteShell::class, $remoteShell);
    $this->app->instance(NodesProbe::class, new NodesProbe);
    $this->probe = app(NodesProbe::class);
});

function roleDriftEntries(Node $node): array
{
    $probe = app(NodesProbe::class);

    return array_values(array_filter(
        $probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([])),
        fn (DriftEntry $entry): bool => str_starts_with($entry->key, 'node.role_'),
    ));
}

it('does not synthesize missing role drift for unassigned nodes', function (): void {
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toBe([]);
});

it('does not synthesize missing role drift for unassigned nodes without a host', function (): void {
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '',
        'wireguard_address' => '10.6.0.6',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toBe([]);
});

it('reports invalid role assignments with unknown roles', function (): void {
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'unknown-role',
        'status' => NodeRoleStatus::Active->value,
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)
        ->toHaveCount(1)
        ->and($roleDrift[0]->key)
        ->toBe('node.role_assignment_invalid')
        ->and($roleDrift[0]->kind)
        ->toBe(DriftKind::Divergent);
});

it('reports invalid role settings when assignment settings do not hydrate', function (): void {
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['unexpected' => true],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)
        ->toHaveCount(1)
        ->and($roleDrift[0]->key)
        ->toBe('node.role_settings_invalid')
        ->and($roleDrift[0]->kind)
        ->toBe(DriftKind::Divergent);
});

it('reports conflicting unresolved role assignments', function (NodeRoleStatus $conflictingStatus): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => $conflictingStatus->value,
    ]);

    $roleDrift = roleDriftEntries($node);
    $conflictDrift = array_values(array_filter(
        $roleDrift,
        fn (DriftEntry $entry): bool => $entry->key === 'node.role_conflict',
    ));

    expect($conflictDrift)->toHaveCount(1)->and($conflictDrift[0]->kind)->toBe(DriftKind::Divergent);
})->with([
    'active' => [NodeRoleStatus::Active],
    'pending' => [NodeRoleStatus::Pending],
    'error' => [NodeRoleStatus::Error],
]);

it('reports invalid role settings when an active app-dev assignment carries a retired tld key', function (): void {
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)
        ->toHaveCount(1)
        ->and($roleDrift[0]->key)
        ->toBe('node.role_settings_invalid')
        ->and($roleDrift[0]->kind)
        ->toBe(DriftKind::Divergent);
});

it('reports convergence failures for error assignments', function (): void {
    $node = Node::factory()->create([
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Error->value,
        'last_error' => 'baseline failed',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)
        ->toHaveCount(1)
        ->and($roleDrift[0]->key)
        ->toBe('node.role_convergence_failed')
        ->and($roleDrift[0]->kind)
        ->toBe(DriftKind::Divergent)
        ->and($roleDrift[0]->detail)
        ->toMatchArray([]);
});

it('reports baseline mismatches for active role-owned artifacts', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)
        ->toHaveCount(1)
        ->and($roleDrift[0]->key)
        ->toBe('node.role_baseline_mismatch')
        ->and($roleDrift[0]->kind)
        ->toBe(DriftKind::Missing)
        ->and($roleDrift[0]->detail)
        ->toMatchArray([
            'role' => 'app-dev',
            'component' => 'tool_config',
            'tool' => 'caddy',
        ]);
});

it('does not require node environment when active role assignments provide the required facts', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $drift = $this->probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([]));
    $recordIncomplete = array_values(array_filter(
        $drift,
        fn (DriftEntry $entry): bool => $entry->key === 'node.record_incomplete',
    ));

    expect($recordIncomplete)->toBeEmpty();
});

it('does not require host for database-only nodes', function (): void {
    $node = Node::factory()->create([
        'name' => 'database',

        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '',
        'wireguard_address' => '10.6.0.6',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $drift = $this->probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([]));
    $recordIncomplete = array_values(array_filter(
        $drift,
        fn (DriftEntry $entry): bool => $entry->key === 'node.record_incomplete',
    ));

    expect($recordIncomplete)->toBeEmpty();
});

it('retries baseline convergence for error assignments during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Error->value,
        'settings' => [],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, function (): NodeRoleBaselineConverger {
        return new class extends NodeRoleBaselineConverger {
            public array $convergedRoles = [];

            public function __construct() {}

            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->convergedRoles[] = $assignment->role;
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('not used');
            }
        };
    });

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_convergence_failed',
        kind: DriftKind::Divergent,
        summary: 'retry role convergence',
        detail: [
            'role' => 'app-dev',
        ],
    ));

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Active)
        ->last_error->toBeNull()
        ->converged_at->not->toBeNull();
});

it('does not reactivate an app production role while the node owns a workspace', function (): void {
    $node = Node::factory()->create([
        'name' => 'future-app-prod',
        'tld' => 'prod',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.9',
        'wireguard_address' => '10.6.0.9',
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'app-prod',
        'status' => NodeRoleStatus::Error->value,
        'settings' => [],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);
    $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
        ),
    ]);
    Workspace::factory()->for($app)->create([
        'name' => 'feature-docs',
        'app_instance_id' => $instance->id,
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, fn (): NodeRoleBaselineConverger => new class extends
        NodeRoleBaselineConverger {
        public function __construct() {}

        public function converge(Node $node, NodeRoleAssignment $assignment): void {}
    });

    expect(fn () => $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_convergence_failed',
        kind: DriftKind::Divergent,
        summary: 'retry production role convergence',
        detail: ['role' => 'app-prod'],
    )))
        ->toThrow(InvalidArgumentException::class, 'feature-docs');

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Error)
        ->last_error->toBe('baseline failed')
        ->converged_at->toBeNull();
});

it('sanitizes workspace permissions when doctor reactivates an app production role', function (): void {
    $node = Node::factory()->create([
        'name' => 'recovered-app-prod',
        'tld' => 'prod',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.10',
        'wireguard_address' => '10.6.0.10',
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'app-prod',
        'status' => NodeRoleStatus::Error->value,
        'settings' => [],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);
    $grant = NodeAccess::query()->create([
        'consumer_node_id' => $node->id,
        'serving_node_id' => $node->id,
        'permissions' => ['*'],
        'custom_permissions' => ['*'],
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, fn (): NodeRoleBaselineConverger => new class extends
        NodeRoleBaselineConverger {
        public function __construct() {}

        public function converge(Node $node, NodeRoleAssignment $assignment): void {}
    });

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_convergence_failed',
        kind: DriftKind::Divergent,
        summary: 'retry production role convergence',
        detail: ['role' => 'app-prod'],
    ));

    $permissions = $grant->fresh()->permissions;
    $customPermissions = $grant->fresh()->custom_permissions;

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Active)
        ->last_error->toBeNull()
        ->converged_at->not->toBeNull()->and($permissions)
        ->not->toContain('*')->and($customPermissions)
        ->not->toContain(
            '*',
        )->and(array_values(array_filter($permissions, fn (string $permission): bool => str_starts_with(
            $permission,
            'workspace:',
        ))))->toBe([])->and(array_values(array_filter($customPermissions, fn (string $permission): bool => str_starts_with(
            $permission,
            'workspace:',
        ))))->toBe([]);
});

it('keeps role assignments errored when convergence retry fails during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Error->value,
        'settings' => [],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, function (): NodeRoleBaselineConverger {
        return new class extends NodeRoleBaselineConverger {
            public function __construct() {}

            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                throw new RuntimeException('baseline still failed');
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('not used');
            }
        };
    });

    expect(fn () => $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_convergence_failed',
        kind: DriftKind::Divergent,
        summary: 'retry role convergence',
        detail: [
            'role' => 'app-dev',
        ],
    )))
        ->toThrow(RuntimeException::class, 'baseline still failed');

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Error)
        ->last_error->toBe('baseline still failed')
        ->converged_at->toBeNull();
});

it('restores role-owned baseline artifacts during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_baseline_mismatch',
        kind: DriftKind::Missing,
        summary: 'restore role baseline',
        detail: [
            'role' => 'app-dev',
            'tld' => 'test',
        ],
    ));

    expect(
        NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->exists(),
    )->toBeTrue();
});

it('only re-converges the role assignment that owns a baseline mismatch', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'tld' => 'test',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $converger = new class extends NodeRoleBaselineConverger {
        public array $convergedRoles = [];

        public function __construct() {}

        public function converge(Node $node, NodeRoleAssignment $assignment): void
        {
            $this->convergedRoles[] = $assignment->role;
        }

        public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
        {
            throw new RuntimeException('not used');
        }
    };

    $this->app->instance(NodeRoleBaselineConverger::class, $converger);

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_baseline_mismatch',
        kind: DriftKind::Missing,
        summary: 'restore role baseline',
        detail: [
            'role' => 'app-dev',
            'tld' => 'test',
        ],
    ));

    expect($converger->convergedRoles)->toBe(['app-dev']);
});

it('re-converges the websocket role for restorable websocket node drift', function (string $key): void {
    $node = Node::factory()->create([
        'name' => 'realtime-1',
        'tld' => 'realtime-1',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.44',
        'wireguard_address' => '10.6.0.44',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $converger = new class extends NodeRoleBaselineConverger {
        public array $convergedRoles = [];

        public function __construct() {}

        public function converge(Node $node, NodeRoleAssignment $assignment): void
        {
            $this->convergedRoles[] = $assignment->role;
        }

        public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
        {
            throw new RuntimeException('not used');
        }
    };

    $this->app->instance(NodeRoleBaselineConverger::class, $converger);

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: $key,
        kind: DriftKind::Divergent,
        summary: 'restore websocket role baseline',
        detail: [
            'role' => 'websocket',
        ],
    ));

    expect($converger->convergedRoles)->toBe(['websocket']);
})->with([
    'backend certificate' => ['node.websocket.backend_cert_missing'],
    'runtime bind' => ['node.websocket.bind_public_interface'],
]);

final class NodesProbeRoleAssignmentsRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
