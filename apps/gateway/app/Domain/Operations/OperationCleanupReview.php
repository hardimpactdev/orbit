<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Models\GatewayRelease;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class OperationCleanupReview
{
    public function __construct(private DirectOperation $operations, private GatewayReleaseSource $source) {}

    /** The release attempt and its checkout must agree, including when two attempts name the same commit. */
    public function servingRelease(GatewayReleaseLayout $layout, int $operationId): ?GatewayRelease
    {
        if (! $layout->usesContainers()) {
            return null;
        }
        $inventory = $layout->observe($layout->applicationPath);
        $sha = $inventory->commits[$inventory->current ?? ''] ?? null;
        if ($inventory->superseded || $sha === null) {
            return null;
        }
        $operation = Operation::query()->where('kind', 'gateway.release')->where('target_type', 'gateway_release')->findOrFail($operationId);
        $release = GatewayRelease::query()->findOrFail($operation->target_id);
        // A host release Operation, the receipt of the first move into a container, names no target. It counts only
        // when its release record verified.
        $target = $operation->input['target'] ?? null;
        $legacy = $target === null && in_array($release->outcome, ['verified', 'reconciled'], true);
        if ($release->commit() !== $sha || ($operation->input['sha'] ?? null) !== $sha || ($target !== 'gateway' && ! $legacy)) {
            throw new RuntimeException('The cleanup check belongs to a different Gateway release.');
        }

        return $release;
    }

    /** @param list<array{kind:string,version:int,eligible:bool,outstanding:int}> $versions */
    public function record(GatewayRelease $release, GatewayReleaseLayout $layout, array $versions): void
    {
        $policies = OperationVersions::policies();
        $known = DB::table('operation_cleanup_reviews')->where('release_id', $release->id)->get(['kind', 'version'])
            ->keyBy(fn (object $row): string => $row->kind.'@'.$row->version);
        $workflowIds = $repository = $parked = null;
        foreach ($versions as $version) {
            ['kind' => $kind, 'version' => $number] = $version;
            $policy = $policies[$kind] ?? null;
            if (! $version['eligible'] || $known->has($kind.'@'.$number) || ! $this->reviewable($kind, $number, $policy)) {
                continue;
            }
            $brief = null;
            if (isset($policy['retained'][$number])) {
                $definition = $this->operations->definition($kind, $number);
                if ($definition === null) {
                    continue;
                }
                $workflowIds ??= $this->workflowIds($layout->currentPath());
                $names = [$definition['workflow'], ...($definition['alternate_workflows'] ?? [])];
                $ids = $workflowIds;
                if (isset($definition['scheduled_result'])) {
                    $scheduled = $definition['scheduled_result'];
                    $names[] = $scheduled['workflow'];
                    $ids[$scheduled['workflow']] = $scheduled['workflow_id'];
                }
                $refs = array_map(fn (string $name): string => $name.' ('.($ids[$name] ?? 'resolve the registered UUID before removal').')', array_unique($names));
                $handlers = array_intersect_key($definition, array_flip(['projection', 'child_projection', 'primitive', 'result_hook', 'github_token_issuer']));
                $brief = "Gateway release {$release->commit()} confirmed zero outstanding Operations for $kind v$number.\n"
                    .'Swamp workflows: '.implode(', ', $refs).".\nGateway handlers: ".implode(', ', array_unique($handlers)).".\n\n"
                    .'Run operations:check-cleanup --json again and confirm eligible for this exact version. '
                    .'Check other current and retained versions, child workflow calls, schedules and grants for shared references. '
                    .'Remove only unused definitions and handlers in a cleanup PR. Keep the admission fence and historical Operations.';
            }
            $repository ??= $this->source->repository();
            $parked ??= json_encode($layout->observe()->parked, JSON_THROW_ON_ERROR);
            DB::table('operation_cleanup_reviews')->insertOrIgnore(['release_id' => $release->id,
                'repository' => "https://github.com/{$repository->owner}/{$repository->name}", 'kind' => $kind, 'version' => $number,
                'brief' => $brief, 'parked' => $parked]);
        }
    }

    /**
     * A row can file its Task only after every stopped release that could serve again when it was recorded is gone.
     * Every release a rollback can start now must have closed the version itself, shown by its own review row.
     * From then on, no rollback can start code that still uses the version. A later verified handover must
     * also have replaced the recording release, so the row's own release never files on its first ticks.
     */
    public function rollbackSafe(stdClass $row, GatewayReleaseLayout $layout, GatewayRelease $live): bool
    {
        if ($live->id <= $row->release_id || in_array($live->commit(), [$row->sha ?? null, $row->requested ?? null], true)) {
            return false;
        }
        $inventory = $layout->observe();
        // Objects stay objects, so {} is no empty list. Only a list of full commits is evidence.
        $parked = json_decode(is_string($row->parked) ? $row->parked : '', false, flags: JSON_THROW_ON_ERROR);
        if (! is_array($parked) || ! array_is_list($parked)
            || array_filter($parked, static fn (mixed $sha): bool => ! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) !== []) {
            throw new RuntimeException("The cleanup review {$row->id} has an invalid stopped-release list.");
        }
        $startable = [...array_values($inventory->commits), ...$inventory->parked];
        if (array_intersect($parked, $startable) !== []) {
            return false;
        }
        foreach ($inventory->parked as $sha) {
            $closed = DB::table('operation_cleanup_reviews as r')->join('gateway_releases as g', 'g.id', '=', 'r.release_id')
                ->where('r.kind', $row->kind)->where('r.version', $row->version)
                ->where(fn ($query) => $query->where('g.sha', $sha)->orWhere('g.requested', $sha))->exists();
            if (! $closed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the serving code no longer admits or runs this version: it is neither current nor a retained
     * version that is open to new work. A rollback that makes the version current again makes it unreviewable.
     *
     * @param  array{current:int,retained:array<int,mixed>,deprecated:list<int>}|null  $policy
     */
    public function reviewable(string $kind, int $version, ?array $policy): bool
    {
        if ($policy !== null) {
            return $policy['current'] !== $version
                && (! array_key_exists($version, $policy['retained']) || in_array($version, $policy['deprecated'], true));
        }

        // A release may remove the entire policy. Confirm that a previously retained mapping
        // disappeared and that the default v1 declaration has not taken its place.
        return $this->operations->definition($kind, $version) === null
            && DB::table('operation_cleanup_reviews')->where('kind', $kind)->where('version', $version)->whereNotNull('brief')->exists();
    }

    /** The registered identity is in the two top-level declaration fields. Filenames can differ from names.
     * @return array<string,string>
     */
    private function workflowIds(string $root): array
    {
        $ids = [];
        foreach (glob($root.'/swamp/workflows/*.yaml') ?: [] as $file) {
            $header = file_get_contents($file, length: 8192);
            if (is_string($header) && preg_match('/^name: ([a-z][a-z0-9_.-]*)\s*$/m', $header, $name)
                && preg_match('/^id: ([a-f0-9-]+)\s*$/m', $header, $id) && Str::isUuid($id[1])) {
                $ids[$name[1]] = $id[1];
            }
        }

        return $ids;
    }
}
