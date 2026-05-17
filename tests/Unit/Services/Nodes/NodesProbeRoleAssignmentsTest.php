<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = app(NodesProbe::class);

    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

function roleDriftEntries(Node $node): array
{
    $probe = app(NodesProbe::class);

    return array_values(array_filter(
        $probe->diff($node->fresh()->load('roleAssignments'), new ProbeSnapshot([])),
        fn (DriftEntry $entry): bool => str_starts_with($entry->key, 'node.role_'),
    ));
}

it('reports missing role assignment when a legacy app node has no compatible assignment', function (): void {
    $node = Node::factory()->create([
        'role' => 'app',
        'environment' => 'development',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_assignment_missing')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Missing);
});

it('reports invalid role assignments with unknown roles', function (): void {
    $node = Node::factory()->create([
        'role' => 'control',
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

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_assignment_invalid')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports invalid role settings when assignment settings do not hydrate', function (): void {
    $node = Node::factory()->create([
        'role' => 'control',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_settings_invalid')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports conflicting active role assignments', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'role' => 'control',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-production',
        'status' => NodeRoleStatus::Active->value,
    ]);

    File::ensureDirectoryExists(storage_path('app/orbit/node-development-dns.d'));
    File::put(storage_path('app/orbit/node-development-dns.d/test.conf'), implode("\n", [
        '# orbit-managed=node-development-dns',
        '# node=test',
        '# bind-scope=orbit_network',
        'address=/.test/10.6.0.5',
        '',
    ]));

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_conflict')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports invalid role settings when an active app-development assignment has no tld', function (): void {
    $node = Node::factory()->create([
        'role' => 'app',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => ''],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_settings_invalid')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent);
});

it('reports convergence failures for error assignments', function (): void {
    $node = Node::factory()->create([
        'role' => 'control',
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

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_convergence_failed')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Divergent)
        ->and($roleDrift[0]->detail)->toMatchArray([
            'role' => 'database',
        ]);
});

it('reports baseline mismatches for active role-owned artifacts', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'role' => 'app',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    $roleDrift = roleDriftEntries($node);

    expect($roleDrift)->toHaveCount(1)
        ->and($roleDrift[0]->key)->toBe('node.role_baseline_mismatch')
        ->and($roleDrift[0]->kind)->toBe(DriftKind::Missing)
        ->and($roleDrift[0]->detail)->toMatchArray([
            'role' => 'app-development',
            'tld' => 'test',
        ]);
});

it('does not require legacy environment when active role assignments provide the required facts', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'role' => 'app',
        'environment' => null,
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    File::ensureDirectoryExists(storage_path('app/orbit/node-development-dns.d'));
    File::put(storage_path('app/orbit/node-development-dns.d/test.conf'), implode("\n", [
        '# orbit-managed=node-development-dns',
        '# node=test',
        '# bind-scope=orbit_network',
        'address=/.test/10.6.0.5',
        '',
    ]));

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
        'role' => 'app',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Error->value,
        'settings' => ['tld' => 'test'],
        'last_error' => 'baseline failed',
        'converged_at' => null,
    ]);

    $this->app->bind(NodeRoleBaselineConverger::class, function (): NodeRoleBaselineConverger {
        return new class extends NodeRoleBaselineConverger
        {
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
            'role' => 'app-development',
        ],
    ));

    expect($assignment->fresh())
        ->status->toBe(NodeRoleStatus::Active->value)
        ->last_error->toBeNull()
        ->converged_at->not->toBeNull();
});

it('restores role-owned settings-derived artifacts during reconcile', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'role' => 'app',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    $this->probe->reconcile($node, new DriftEntry(
        family: 'nodes',
        key: 'node.role_baseline_mismatch',
        kind: DriftKind::Missing,
        summary: 'restore role baseline',
        detail: [
            'role' => 'app-development',
            'tld' => 'test',
        ],
    ));

    expect(storage_path('app/orbit/node-development-dns.d/test.conf'))
        ->toBeFile();
});

it('only re-converges the role assignment that owns a baseline mismatch', function (): void {
    $node = Node::factory()->create([
        'name' => 'test',
        'role' => 'app',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.0.0.1',
        'wireguard_address' => '10.6.0.5',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $converger = new class extends NodeRoleBaselineConverger
    {
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
            'role' => 'app-development',
            'tld' => 'test',
        ],
    ));

    expect($converger->convergedRoles)->toBe(['app-development']);
});
