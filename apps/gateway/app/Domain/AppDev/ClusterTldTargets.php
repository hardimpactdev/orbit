<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;

/** The TLD of each active Cluster with a Router, and the Router's WireGuard address that answers it. Reads stored rows. */
final readonly class ClusterTldTargets
{
    /** @return array<string, string> TLD => address */
    public function all(): array
    {
        $targets = [];

        foreach (Cluster::query()->orderBy('id')->get() as $cluster) {
            $pending = $cluster->pendingDns();
            $state = array_key_exists('state', $pending) ? ClusterState::tryFrom($pending['state']) : $cluster->state;

            if ($state !== ClusterState::Active) {
                continue;
            }

            $tld = $this->name(array_key_exists('tld', $pending) ? $pending['tld'] : $cluster->tld);
            $router = $this->router($cluster, $pending);
            $address = $router instanceof Node ? app(RouteRenderContext::class)->wireguardIp($router) : null;

            if ($tld !== null && is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $targets[$tld] = $address;
            }
        }

        return $targets;
    }

    /**
     * A pending Router (a pending null means no Router), else a Router replacement that has published DNS, else the active Router.
     *
     * @param  array{tld?: ?string, state?: string, router_node_id?: ?int}  $pending
     */
    private function router(Cluster $cluster, array $pending): ?Node
    {
        if (array_key_exists('router_node_id', $pending)) {
            return is_int($pending['router_node_id']) ? Node::query()->find($pending['router_node_id']) : null;
        }

        $candidateId = new ClusterRouterTransition()->dnsRouters()[$cluster->id] ?? null;

        if (is_int($candidateId)) {
            return Node::query()->find($candidateId);
        }

        $assignment = NodeRole::query()
            ->where('cluster_id', $cluster->id)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->with('node')
            ->first();

        return $assignment instanceof NodeRole ? $assignment->node : null;
    }

    private function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? rtrim(strtolower($value), '.') : null;
    }
}
