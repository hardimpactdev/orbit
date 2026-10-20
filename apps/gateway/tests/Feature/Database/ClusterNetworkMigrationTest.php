<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('cluster and node network migration', function (): void {
    it('enforces Cluster-scoped LAN and Router ownership constraints', function (): void {
        $firstCluster = DB::table('clusters')->insertGetId([
            'name' => 'first',
            'state' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondCluster = DB::table('clusters')->insertGetId([
            'name' => 'second',
            'state' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $firstNode = DB::table('nodes')->insertGetId([
            ...clusterLegacyNode('first-node', '192.0.2.11', now()),
            'cluster_id' => $firstCluster,
            'lan_ip' => '10.0.0.10',
        ]);
        $secondNode = DB::table('nodes')->insertGetId([
            ...clusterLegacyNode('second-node', '192.0.2.12', now()),
            'cluster_id' => $secondCluster,
            'lan_ip' => '10.0.0.10',
        ]);

        expect($firstNode)->toBeInt()->and($secondNode)->toBeInt();

        expect(fn () => DB::table('nodes')->insert([
            ...clusterLegacyNode('duplicate-lan', '192.0.2.13', now()),
            'cluster_id' => $firstCluster,
            'lan_ip' => '10.0.0.10',
        ]))
            ->toThrow(QueryException::class);

        DB::table('node_roles')->insert([
            'node_id' => $firstNode,
            'cluster_id' => $firstCluster,
            'role' => 'router',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('node_roles')->insert([
            'node_id' => DB::table('nodes')->insertGetId([
                ...clusterLegacyNode('other-first-node', '192.0.2.14', now()),
                'cluster_id' => $firstCluster,
            ]),
            'cluster_id' => $firstCluster,
            'role' => 'router',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
            ->toThrow(QueryException::class);

        expect(fn () => DB::table('node_roles')->insert([
            'node_id' => $secondNode,
            'cluster_id' => $firstCluster,
            'role' => 'router',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
            ->toThrow(QueryException::class);
    });

    it('enforces active Ingress cardinality per Cluster without disturbing existing assignments', function (): void {
        $firstCluster = clusterMigrationCluster('ingress-first');
        $secondCluster = clusterMigrationCluster('ingress-second');
        $firstNode = clusterMigrationNode('ingress-first-node', '192.0.2.21', $firstCluster);
        $secondNode = clusterMigrationNode('ingress-second-node', '192.0.2.22', $firstCluster);
        $otherClusterNode = clusterMigrationNode('ingress-other-cluster-node', '192.0.2.23', $secondCluster);
        $firstAssignment = clusterMigrationRole($firstNode, $firstCluster, 'ingress', 'active');

        expect(fn () => clusterMigrationRole($secondNode, $firstCluster, 'ingress', 'active'))
            ->toThrow(QueryException::class);

        $otherAssignment = clusterMigrationRole($otherClusterNode, $secondCluster, 'ingress', 'active');

        expect(DB::table('node_roles')->where('id', $firstAssignment)->sole()->node_id)
            ->toBe($firstNode)
            ->and(DB::table('node_roles')->where('id', $otherAssignment)->sole()->node_id)
            ->toBe($otherClusterNode)
            ->and(DB::table('node_roles')->where('role', 'ingress')->count())
            ->toBe(2);
    });

    it('rejects Ingress ownership outside the assigned Node Cluster', function (?int $clusterChoice): void {
        $nodeCluster = clusterMigrationCluster('ownership-node-'.($clusterChoice ?? 'none'));
        $otherCluster = clusterMigrationCluster('ownership-other-'.($clusterChoice ?? 'none'));
        $node = clusterMigrationNode('ownership-node-'.($clusterChoice ?? 'none'), '192.0.2.31', $nodeCluster);
        $assignmentCluster = $clusterChoice === 1 ? $otherCluster : null;

        expect(fn () => clusterMigrationRole($node, $assignmentCluster, 'ingress', 'active'))
            ->toThrow(QueryException::class)
            ->and(DB::table('node_roles')->where('node_id', $node)->exists())
            ->toBeFalse();
    })->with([
        'missing Cluster identity' => null,
        'different Cluster identity' => 1,
    ]);

    it('protects Cluster membership while any Ingress assignment row exists', function (
        string $status,
        ?string $failedStep,
    ): void {
        $cluster = clusterMigrationCluster("membership-{$status}");
        $node = clusterMigrationNode("membership-node-{$status}", '192.0.2.41', $cluster);
        $assignment = clusterMigrationRole(
            $node,
            $cluster,
            'ingress',
            $status,
            $failedStep,
        );

        expect(fn () => DB::table('nodes')->where('id', $node)->update(['cluster_id' => null]))
            ->toThrow(QueryException::class)
            ->and(DB::table('nodes')->where('id', $node)->sole()->cluster_id)
            ->toBe($cluster)
            ->and(DB::table('node_roles')->where('id', $assignment)->sole()->status)
            ->toBe($status);

        DB::table('node_roles')->where('id', $assignment)->delete();
        DB::table('nodes')->where('id', $node)->update(['cluster_id' => null]);

        expect(DB::table('nodes')->where('id', $node)->sole()->cluster_id)->toBeNull();
    })->with([
        'provisioning' => ['provisioning', null],
        'active' => ['active', null],
        'removing' => ['removing', null],
        'retryable convergence failure' => ['failed', 'converge:baseline'],
        'retryable removal failure' => ['failed', 'remove:baseline'],
    ]);
});

function clusterMigrationCluster(string $name): int
{
    return DB::table('clusters')->insertGetId([
        'name' => $name,
        'state' => 'inactive',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function clusterMigrationNode(string $name, string $host, int $cluster): int
{
    return DB::table('nodes')->insertGetId([
        ...clusterLegacyNode($name, $host, now()),
        'cluster_id' => $cluster,
    ]);
}

function clusterMigrationRole(
    int $node,
    ?int $cluster,
    string $role,
    string $status,
    ?string $failedStep = null,
): int {
    return DB::table('node_roles')->insertGetId([
        'node_id' => $node,
        'cluster_id' => $cluster,
        'role' => $role,
        'status' => $status,
        'failed_step' => $failedStep,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function clusterLegacyNode(string $name, string $host, mixed $timestamp): array
{
    return [
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => $host,
        'user' => 'orbit',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}
