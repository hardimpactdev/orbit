<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Freezes both sides of an update without Gateway execution phases. */
final readonly class NativeRouteUpdateIntent
{
    public function __construct(private NativeRouteConvergenceIntent $convergence) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function forRoute(Route $route, array $input): array
    {
        $mode = $input['mode'] ?? 'publication';
        if (! in_array($mode, ['replace_domain', 'replace_pending', 'publication', 'web_root', 'state_only'], true)) {
            throw new ResourceOperationException('route.update_invalid', 'The Route update mode is not supported.', 422);
        }
        $before = $this->convergence->forRoute($route, ['mode' => $mode]);
        $candidate = clone $route;
        if (in_array($mode, ['replace_domain', 'replace_pending'], true)) {
            $candidate = Route::query()->findOrFail(ValidatedData::integer($input['candidate_route_id']));
            if ($route->replaced_by_route_id !== $candidate->id || $candidate->replaces_route_id !== $route->id) {
                throw new ResourceOperationException('route.domain_change_conflict', 'The recorded Route replacement pair changed.', 409);
            }
        } elseif ($mode === 'web_root') {
            $candidate->web_root = ValidatedData::nullableString($input['web_root']);
        } elseif ($mode === 'publication') {
            $candidate->publication = RoutePublication::from(ValidatedData::string($input['publication']));
        }
        $after = $this->convergence->forRoute($candidate, ['mode' => $mode]);
        $cleanupRoute = clone $candidate;
        $cleanupRoute->replacement_step = RouteReplacementStep::Cleanup;
        $cleanup = $this->convergence->forRoute($cleanupRoute, ['mode' => $mode]);
        $remove = [];
        if ($mode === 'replace_domain') {
            foreach ([...$before['certificates'], ...$after['certificates']] as $leaf) {
                if (str_ends_with($leaf['scope'], '-hostname-change') || str_starts_with($leaf['scope'], 'route-'.$route->id.'-')) {
                    $remove[] = array_intersect_key($leaf, array_flip(['node_id', 'scope', 'home', 'agent_sha256'])) + ['in_use' => false];
                }
            }
        } elseif ($mode === 'web_root' && $route->web_root !== null && $candidate->web_root === null) {
            foreach ($before['certificates'] as $leaf) {
                if ($leaf['scope'] === 'route-'.$route->id) {
                    $remove[] = array_intersect_key($leaf, array_flip(['node_id', 'scope', 'home', 'agent_sha256'])) + ['in_use' => false];
                }
            }
        }
        $nodes = array_values(array_unique([...$before['node_ids'], ...$after['node_ids']]));
        sort($nodes);

        return ['route_id' => $route->id, 'result_route_id' => $candidate->id, 'mode' => $mode,
            'previous' => $before, 'desired' => $after, 'cleanup' => $cleanup, 'cleanup_removals' => $remove, 'node_ids' => $nodes,
            'pending' => $mode === 'replace_pending', 'changed' => $mode !== 'state_only'
                && ($route->domain !== $candidate->domain || $route->web_root !== $candidate->web_root || $route->publication !== $candidate->publication)];
    }

    /** @return list<int> */
    public static function participants(Operation $operation): array
    {
        return ValidatedData::integerList(ValidatedData::object($operation->input['route_update'] ?? [])['node_ids'] ?? []);
    }

    /** @return array<string, mixed> */
    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $intent = ValidatedData::object($operation->input['route_update'] ?? []);
        if (! in_array($callerNodeId, self::participants($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Route update participant.', 403);
        }
        $view = clone $operation;
        $view->input = [...$operation->input, 'route_convergence' => [...ValidatedData::object($intent['desired']), 'node_ids' => $intent['node_ids']]];
        $public = $this->convergence->publicInput($view, $callerNodeId);

        return [...$public, 'update' => $intent];
    }
}
