<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;

/** Capture retained Route rows before a Node parent records withdrawal intent. */
final readonly class NativeRouteRemovalPlan
{
    public function __construct(private NativeRouteRemovalIntent $intent) {}

    /** The parent may supply its already-frozen complete Route IDs.
     * @param  list<int>|null  $routeIds
     * @return array<string,mixed>
     */
    public function captureForNode(Node $node, ?array $routeIds = null): array
    {
        $routes = Route::query()->when($routeIds !== null,
            static fn (Builder $query): Builder => $query->whereKey($routeIds),
            static fn (Builder $query): Builder => $query->where(static fn (Builder $query): Builder => $query
                ->where('node_id', $node->id)->orWhere('transition_node_id', $node->id)->orWhere('generation_basis_node_id', $node->id)
                ->orWhereHas('customProxy', static fn (Builder $query): Builder => $query->where('node_id', $node->id))
                ->orWhereHas('targets.instance', static fn (Builder $query): Builder => $query->where('node_id', $node->id))))
            ->orderBy('id')->get();

        return [...$this->intent->captureMany($routes, [$node]), 'removed_node_id' => $node->id];
    }

    /** Record the parent's desired withdrawal in its admission transaction.
     * Rows and associations remain attached until final success projection.
     *
     * @param  array{routes:list<array{route_id:int}>}  $snapshot
     */
    public function recordWithdrawal(array $snapshot): void
    {
        foreach ($snapshot['routes'] as $member) {
            $route = Route::query()->whereKey($member['route_id'])->firstOrFail();
            $this->intent->forRoute($route);
        }
    }
}
