<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Jobs\Operations\ReconcileSwampOperations;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The admission tombstone and the ledger inspection share one Gateway SQLite writer transaction. */
final readonly class OperationVersionCleanup
{
    public function __construct(private DirectOperation $direct) {}

    /** @return array{fenced:bool} */
    public function fenceOnly(): array
    {
        DB::transaction(fn () => $this->fence($this->direct->dependencyGraph()), 3);

        return ['fenced' => true];
    }

    /**
     * Whether this process runs the release that serves traffic. A host Gateway always does. In a release container,
     * marked by its runtime, the host inventory must be readable and name this checkout as live; a draining outgoing
     * release then no longer writes its own policy into the fence from a check. The switch's pre-publication fence is
     * the exception: the release about to serve owns the fence from its switch on.
     */
    public function serves(): bool
    {
        if (! config()->boolean('orbit.gateway_container')) {
            return true;
        }
        $layout = GatewayReleaseLayout::fromConfig();
        try {
            return $layout->usesContainers() && ! $layout->observe($layout->applicationPath)->superseded;
        } catch (GatewayReleaseException) {
            return false;
        }
    }

    /** @param array<string,array<int,array<string,int>>> $graph */
    private function fence(array $graph): void
    {
        // Persist transitive edges so even an older binary inserting a v1 row without a child snapshot hits the same fence.
        foreach ($graph as $kind => $versions) {
            foreach (array_keys($versions) as $version) {
                foreach ($this->descendants($graph, $kind, $version) as [$child, $pinned]) {
                    DB::table('operation_version_dependencies')->insertOrIgnore(['kind' => $kind, 'version' => $version,
                        'child_kind' => $child, 'child_version' => $pinned, 'direct' => ($graph[$kind][$version][$child] ?? null) === $pinned]);
                }
            }
        }
        $policies = OperationVersions::policies();
        foreach ($policies as $kind => $policy) {
            foreach ($policy['deprecated'] as $version) {
                DB::table('deprecated_operation_versions')->insertOrIgnore(['kind' => $kind, 'version' => $version]);
            }
        }
        // The release that is about to serve owns the fence. After a rollback, it admits versions that the
        // rolled-back-from release closed, so it reopens every version that it can still start itself.
        // A kind it does not define stays fenced.
        $defined = $this->direct->definitions();
        foreach (DB::table('deprecated_operation_versions')->get(['kind', 'version']) as $row) {
            $policy = $policies[$row->kind] ?? (array_key_exists($row->kind, $defined) ? ['current' => 1, 'retained' => [], 'deprecated' => []] : null);
            if ($policy === null) {
                continue;
            }
            $version = (int) $row->version;
            if (($version === $policy['current'] || array_key_exists($version, $policy['retained'])) && ! in_array($version, $policy['deprecated'], true)) {
                DB::table('deprecated_operation_versions')->where('kind', $row->kind)->where('version', $version)->delete();
            }
        }
    }

    /** @return array{eligible:bool,versions:list<array{kind:string,version:int,eligible:bool,outstanding:int}>} */
    public function check(): array
    {
        $graph = $this->direct->dependencyGraph();

        return DB::transaction(function () use ($graph): array {
            if ($this->serves()) {
                $this->fence($graph);
            }
            $counts = [];
            foreach (DB::table('deprecated_operation_versions')->orderBy('kind')->orderBy('version')->get() as $row) {
                $counts[$row->kind.'@'.$row->version] = ['kind' => (string) $row->kind, 'version' => (int) $row->version, 'eligible' => true, 'outstanding' => 0];
            }
            if ($counts === []) {
                return ['eligible' => true, 'versions' => []];
            }
            // Queued retries, waiting children, late run terminals, and released-but-still-running timed-out work can all need their original handler.
            $pending = ReconcileSwampOperations::open()->orWhereNotNull('lock_key')
                ->orWhere(fn ($q) => $q->where('kind', OperationKinds::ConnLaunch)->where('status', OperationStatus::Succeeded)
                    ->whereNotNull('state->launch_retry_requests->application->id'))
                ->orWhere(fn ($q) => $q->where('error_code', 'operation.timed_out')->whereNotNull('state->native_timeout')
                    ->whereNull('state->native_timeout->no_run_verified_at')
                    ->where(fn ($query) => $query->whereNull('state->native_timeout->native_terminal_status')
                        ->orWhere('state->native_timeout->native_terminal_status', 'unproven')))
                ->select('id')->toBase();
            $sql = $pending->toSql();
            // One set-based read covers parent receipts, legacy proof children, and work still reachable inside a native parent.
            // UNION also bounds traversal if a historical parent link contains a cycle.
            $outstanding = DB::select(<<<SQL
                WITH RECURSIVE affected(root_id, id) AS (
                    SELECT id, id FROM ({$sql})
                    UNION
                    SELECT a.root_id, p.id FROM affected AS a JOIN operations AS c ON c.id = a.id
                    JOIN operations AS p ON p.id = c.parent_operation_id OR
                      (c.parent_operation_id IS NULL AND c.kind = 'proof.command' AND p.kind = 'proof.run'
                       AND p.id = json_extract(c.input, '$.proof_operation_id')) OR
                      (c.parent_operation_id IS NULL AND p.kind = 'conn.launch' AND
                       (p.id = json_extract(c.state, '$.conn_launch_owner') OR
                        (c.kind IN ('conn.launch_inspect','conn.launch_dispatch') AND p.id = json_extract(c.input, '$.launch_operation_id'))))
                )
                SELECT v.kind, v.version, COUNT(DISTINCT a.root_id) AS total
                FROM affected AS a JOIN operations AS o ON o.id = a.id JOIN deprecated_operation_versions AS v ON
                    (v.kind = o.kind AND v.version = o.operation_version)
                    OR ((o.kind != 'conn.launch' OR o.status != 'succeeded' OR v.kind = 'instance.complete') AND (
                        EXISTS (SELECT 1 FROM operation_version_dependencies AS d WHERE d.kind = o.kind AND d.version = o.operation_version
                            AND d.child_kind = v.kind AND d.child_version = v.version)
                        OR EXISTS (SELECT 1 FROM json_each(o.child_versions) AS child WHERE child.key = v.kind AND child.value = v.version)))
                GROUP BY v.kind, v.version
                SQL, $pending->getBindings());
            foreach ($outstanding as $row) {
                $key = $row->kind.'@'.$row->version;
                $counts[$key] = ['kind' => (string) $row->kind, 'version' => (int) $row->version, 'eligible' => false, 'outstanding' => (int) $row->total];
            }
            foreach ($counts as $item) {
                if ($item['eligible']) {
                    DB::table('deprecated_operation_versions')->where('kind', $item['kind'])->where('version', $item['version'])
                        ->whereNull('eligible_at')->update(['eligible_at' => now()]);
                }
            }

            return ['eligible' => ! array_any($counts, static fn (array $item): bool => ! $item['eligible']), 'versions' => array_values($counts)];
        }, 3);
    }

    /** @param array<string,array<int,array<string,int>>> $graph
     * @param  list<string>  $path
     * @return list<array{string,int}>
     */
    private function descendants(array $graph, string $kind, int $version, array $path = []): array
    {
        $key = $kind.'@'.$version;
        if (in_array($key, $path, true)) {
            throw new LogicException('A versioned Operation cannot depend on itself.');
        }
        $children = [];
        foreach ($graph[$kind][$version] ?? [] as $child => $pinned) {
            $children[] = [$child, $pinned];
            array_push($children, ...$this->descendants($graph, $child, $pinned, [...$path, $key]));
        }

        return $children;
    }
}
