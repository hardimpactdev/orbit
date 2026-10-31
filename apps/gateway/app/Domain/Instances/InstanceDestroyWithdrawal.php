<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\NativeRouteRemovalIntent;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWithdrawalArtifacts;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Collection;

/** Adapts the Route owner's complete withdrawal capture; performs no Node work. */
final readonly class InstanceDestroyWithdrawal
{
    public function __construct(private RouteWithdrawalArtifacts $development, private NativeRouteRemovalIntent $routes) {}

    /** @param Collection<int,Route> $routes
     * @return array<string,mixed>
     */
    public function forInstance(Instance $instance, Collection $routes): array
    {
        if ($instance->placedOnAppDev()) {
            return $this->development->forDevelopmentRemoval($instance, $routes);
        }
        if (! $instance->placedOnAppProd() || $instance->status !== InstanceState::Removing
            || $instance->routes()->orderBy('routes.id')->pluck('routes.id')->all() !== $routes->pluck('id')->sort()->values()->all()) {
            throw new ResourceOperationException('route.withdrawal_invalid', 'Capture every Route of the removing production Instance.', 409);
        }
        foreach ($routes as $route) {
            $route->loadMissing('targets');
            if ($route->kind !== RouteKind::App || $route->project_id !== $instance->project_id
                || $route->status !== RouteStatus::Retiring || $route->sites_published
                || $route->targets->count() !== 1 || $route->targets->sole()->instance_id !== $instance->id) {
                throw new ResourceOperationException('route.withdrawal_invalid', 'Production removal requires exclusive withdrawing Route targets.', 409);
            }
        }
        $snapshot = $this->routes->captureMany($routes, [$instance->node]);
        $nodes = [];
        foreach ($snapshot['nodes'] as $member) {
            $node = Node::query()->findOrFail($member['node_id']);
            $scopes = $member['certificate_scopes'];
            if ($node->id === $instance->node_id) {
                $scopes[] = 'app-instance-'.$instance->id;
            }
            $nodes[] = [...$member, 'certificate_scopes' => array_values(array_unique($scopes)),
                'caddy' => CaddyPublicationPlan::forNode($node)];
        }
        $dns = [];
        foreach ($snapshot['dns_node_ids'] as $nodeId) {
            $dns[] = app(NativeRoutePublicationInput::class)->dns(Node::query()->findOrFail(ValidatedData::integer($nodeId)));
        }

        return ['schema' => 'route.withdrawal.v1', 'instance_id' => $instance->id,
            'route_ids' => $routes->pluck('id')->sort()->values()->all(), 'nodes' => $nodes, 'dns' => $dns];
    }
}
