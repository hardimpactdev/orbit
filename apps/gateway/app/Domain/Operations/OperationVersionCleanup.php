<?php

declare(strict_types=1);

namespace App\Domain\Operations;

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
        foreach (OperationVersions::policies() as $kind => $policy) {
            foreach ($policy['deprecated'] as $version) {
                DB::table('deprecated_operation_versions')->insertOrIgnore(['kind' => $kind, 'version' => $version]);
            }
        }
    }

    /** @return array{eligible:bool,versions:list<array{kind:string,version:int,eligible:bool,outstanding:int}>} */
    public function check(): array
    {
        $graph = $this->direct->dependencyGraph();

        return DB::transaction(function () use ($graph): array {
            $this->fence($graph);
            $counts = [];
            foreach (DB::table('deprecated_operation_versions')->orderBy('kind')->orderBy('version')->get() as $row) {
                $counts[$row->kind.'@'.$row->version] = ['kind' => (string) $row->kind, 'version' => (int) $row->version, 'eligible' => true, 'outstanding' => 0];
            }
            if ($counts === []) {
                return ['eligible' => true, 'versions' => []];
            }
            // Queued retries, waiting children, late run terminals, and released-but-still-running timed-out work can all need their original handler.
            $pending = ReconcileSwampOperations::open()->orWhereNotNull('lock_key')
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
                       AND p.id = json_extract(c.input, '$.proof_operation_id'))
                )
                SELECT v.kind, v.version, COUNT(DISTINCT a.root_id) AS total
                FROM affected AS a JOIN operations AS o ON o.id = a.id JOIN deprecated_operation_versions AS v ON
                    (v.kind = o.kind AND v.version = o.operation_version)
                    OR EXISTS (SELECT 1 FROM operation_version_dependencies AS d WHERE d.kind = o.kind AND d.version = o.operation_version
                        AND d.child_kind = v.kind AND d.child_version = v.version)
                    OR EXISTS (SELECT 1 FROM json_each(o.child_versions) AS child WHERE child.key = v.kind AND child.value = v.version)
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
