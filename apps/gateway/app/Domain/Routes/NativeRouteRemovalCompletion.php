<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;
use App\Models\RouteRemovalResidue;

/** A parent records its successful Swamp withdrawal without rebuilding execution proof. */
final readonly class NativeRouteRemovalCompletion
{
    /** @param array{routes:list<array{route_id:int,domain:string,replacement_of:?int}>} $intent
     * @param  list<array{node_id:int,steps:list<string>}>  $retainedOnNodes
     */
    public function apply(array $intent, bool $destroy = true, array $retainedOnNodes = []): void
    {
        foreach ($intent['routes'] as $row) {
            foreach ($retainedOnNodes as $node) {
                RouteRemovalResidue::query()->updateOrCreate(['node_id' => $node['node_id'], 'route_id' => $row['route_id']],
                    ['domain' => $row['domain'], 'steps' => $node['steps']]);
            }
            if ($destroy) {
                if ($row['replacement_of'] !== null) {
                    Route::query()->whereKey($row['replacement_of'])->update(['replaced_by_route_id' => null]);
                }
                Route::query()->whereKey($row['route_id'])->delete();
            }
        }
    }
}
