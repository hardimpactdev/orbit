<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RoutePublication;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListRoutesAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return Collection<int, Route> */
    public function handle(Node $consumer): Collection
    {
        $accessible = $this->access->accessibleNodeIds($consumer);

        return Route::query()
            ->with(['targets', 'customProxy', 'analyticsTracking'])
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->where(
                    fn ($routes) => $routes
                        ->whereIn('node_id', $accessible)
                        ->orWhere(fn (Builder $cluster) => $cluster->where('kind', '!=', RouteKind::CustomProxy)
                            ->whereHas('cluster.nodes', fn ($nodes) => $nodes->whereIn('nodes.id', $accessible)))
                        ->orWhere(fn (Builder $public) => $public->where('kind', RouteKind::CustomProxy)->where('publication', RoutePublication::Public)
                            ->whereHas('customProxy', fn ($proxy) => $proxy->whereIn('node_id', $accessible))
                            ->whereHas('cluster.ingressAssignment', fn ($ingress) => $ingress->whereIn('node_id', $accessible))),
                ),
            )
            ->latest('id')
            ->get();
    }
}
