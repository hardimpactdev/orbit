<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Engine\Engine;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * The Nodes a Route write changes files on, from its admitted plans, and the one exclusion between an engine-run Route update
 * and every other Route writer. The two never queue behind each other: whichever comes second is refused, so no snapshot either
 * captured is dispatched after the other changed its Nodes. Swamp writers among themselves keep RoutePublicationHolds.
 */
final readonly class RouteWriteNodes
{
    /** Plan lists that name Nodes whose files a step writes (NativeRouteUpdatePlan, NativeRouteTargetPlan, route removal). */
    private const array NodeLists = ['site_nodes', 'edge_nodes', 'retire_nodes', 'dns_node_ids'];

    /**
     * Every Node the write's plans change: serving publications, each phase's site Nodes, the edge and retired Nodes, and the VPN
     * Nodes whose DNS records and catalog it writes.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<int>
     */
    public static function of(array $input): array
    {
        $nodes = [];
        self::collect($input, $nodes);
        $nodes = array_values(array_unique($nodes));
        sort($nodes);

        return $nodes;
    }

    /**
     * Refuses an admitted Route write while an open engine-run Route update writes one of its Nodes (StartOperationAction, right
     * after the record exists, so an engine start that binds later sees it). The write is failed before it holds or dispatches.
     */
    public static function refuseBesideEngine(Operation $operation): void
    {
        if (! RoutePublicationHolds::tracks($operation->kind)) {
            return;
        }
        $nodes = self::of($operation->input ?? []);
        if ($nodes === []) {
            return;
        }
        $engine = Operation::query()->where('kind', RouteUpdateEngineFlow::Kind)->whereKeyNot($operation->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->get()
            ->first(static fn (Operation $open): bool => Engine::owns($open) && array_intersect($nodes, self::written($open)) !== []);
        if ($engine === null) {
            return;
        }
        $error = new ResourceOperationException('route.publication_held', 'An engine-run Route update writes a Node of this Route write.', 409,
            details: ['operation_id' => $engine->id]);
        app(StartSwampRun::class)->refuse($operation, $error);

        throw $error;
    }

    /**
     * Refuses an engine-run Route update's start, in its bind transaction, while another open Route write, on either engine, writes
     * or holds one of its Nodes.
     *
     * @param  list<int>  $nodes
     */
    public static function refuseEngineBeside(Operation $operation, array $nodes): void
    {
        if ($nodes === []) {
            return;
        }
        $held = DB::table('route_publication_holds')->whereIn('node_id', $nodes)->where('operation_id', '!=', $operation->id)->exists()
            || DB::table('route_withdrawal_holds')->whereIn('node_id', $nodes)->where('operation_id', '!=', $operation->id)->exists();
        $other = $held ? null : Operation::query()->whereKeyNot($operation->id)->whereNotIn('status', OperationStatus::terminalValues())->get()
            ->first(static fn (Operation $open): bool => RoutePublicationHolds::tracks($open->kind) && array_intersect($nodes, self::written($open)) !== []);
        if ($held || $other !== null) {
            throw new ResourceOperationException('route.publication_held', 'Another Route write writes a Node of this update.', 409,
                details: $other === null ? [] : ['operation_id' => $other->id]);
        }
    }

    /** @return list<int> */
    private static function written(Operation $operation): array
    {
        $nodes = self::of($operation->input ?? []);
        $resumed = $operation->kind === RouteUpdateEngineFlow::Kind ? RouteUpdateEngineFlow::cutoverOwner($operation) : null;

        return $resumed === null ? $nodes : self::of([$nodes, $resumed->input ?? []]);
    }

    /** @param array<int, int> $nodes */
    private static function collect(mixed $value, array &$nodes): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (in_array($key, self::NodeLists, true) && is_array($child)) {
                array_push($nodes, ...array_values(array_filter($child, is_int(...))));
            } elseif ($key === 'publications' && is_array($child)) {
                foreach ($child as $publication) {
                    if (is_array($publication) && ($publication['serves_routes'] ?? false) === true && is_int($publication['node_id'] ?? null)) {
                        $nodes[] = $publication['node_id'];
                    }
                }
            } elseif ($key === 'dns' && is_array($child)) {
                foreach ($child as $row) {
                    if (is_array($row) && is_int($row['node_id'] ?? null)) {
                        $nodes[] = $row['node_id'];
                    }
                }
            }
            self::collect($child, $nodes);
        }
    }
}
