<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationFamilyChildren;
use App\Models\Operation;
use App\Models\Route;

/** Narrow ownership of generated-domain changes; normal Route admission still validates the mutation.
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class GeneratedRouteUpdateOwnership
{
    /** @param ChildIntent $intent */
    public function allows(Operation $parent, Route $route, array $intent): bool
    {
        if ($parent->kind === 'instance.rename' && $parent->target_type === 'instance') {
            return $route->targets()->where('instance_id', $parent->target_id)->exists();
        }
        $input = $intent['input'];
        if ($parent->kind !== 'node.add' || $parent->target_type !== 'node' || $route->provenance !== RouteProvenance::Generated
            || ! is_int($intent['node_id']) || ! is_int($route->generation_basis_node_id) || $route->generation_basis_node_id !== $parent->target_id
            || ! is_string($input['domain'] ?? null)
            || ! OperationChildIntent::same($input, ['route_id' => $route->id, 'domain' => $input['domain']])) {
            return false;
        }
        $changes = data_get($parent->state, 'enrollment_intent.route_changes');
        if (! is_array($changes) || ! array_is_list($changes)) {
            return false;
        }
        $expected = ['route_id' => $route->id, 'domain' => $input['domain'], 'node_id' => $intent['node_id']];

        return array_any($changes, static fn (mixed $change): bool => is_array($change) && OperationChildIntent::same($change, $expected));
    }
}
