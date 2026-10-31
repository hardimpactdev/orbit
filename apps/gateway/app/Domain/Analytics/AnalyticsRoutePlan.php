<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * Public tracking-host intent reusable by Analytics and Route parent admission.
 *
 * @phpstan-import-type Leaf from AnalyticsTrackingIntent
 *
 * @phpstan-type Plan array{route_id:int,certificates:list<Leaf>,cleanup:list<Leaf>,caddy_node_ids:list<int>,dns_node_ids:list<int>,router_node_ids:list<int>,ingress_node_ids:list<int>,public_verifications:list<array<string,mixed>>}
 */
final readonly class AnalyticsRoutePlan
{
    public function __construct(private AgentDelivery $agents, private ManagedUserAccountResolver $accounts,
        private LeafCertificateSigner $signer, private IngressSiteRepository $sites) {}

    /** @return Plan */
    public function capture(Route $route, bool $removing = false): array
    {
        if (! $route->isAnalyticsTracking()) {
            throw new ResourceOperationException('route.kind_unsupported', 'A tracking host requires an analytics Route.', 409);
        }
        $host = $this->sites->routerNode($route);
        $current = $this->leaf($route);
        $certificates = [];
        if (! $removing) {
            $root = $this->signer->rootCertificate();
            $certificates[] = [...$current, 'version' => substr(hash('sha256', $root.':'.$route->domain.':'.$current['scope']), 0, 16),
                'root_hash' => hash('sha256', $root)];
        }
        $cleanup = [];
        $nodes = [];
        $ingress = [];
        foreach (self::placements($route) as $index => $placement) {
            $leaf = $index === 0 ? $current : $this->leaf($placement);
            $nodes[] = $leaf['node_id'];
            if ($removing || $leaf['node_id'] !== $host->id) {
                $cleanup[$leaf['node_id'].':'.$leaf['scope']] = [...$leaf, 'in_use' => false];
            }
            if ($placement->cluster_id !== null && $placement->publication === RoutePublication::Public) {
                $node = $this->sites->ingressNode($placement);
                $nodes[] = $node->id;
                $ingress[] = $node->id;
            }
        }
        $verifications = [];
        if (! $removing && $route->publication === RoutePublication::Public) {
            $node = $this->sites->ingressNode($route);
            $verifications[] = ['node_id' => $node->id, 'verification' => ['protocol' => 'tls',
                'agent_sha256' => $this->agents->pin($node),
                'probe' => ['address' => $this->sites->privateOverride($route)->routerAddress, 'port' => 443, 'domain' => $route->domain]]];
        }
        $nodes = array_values(array_unique($nodes));
        sort($nodes);

        return ['route_id' => $route->id, 'certificates' => $certificates, 'cleanup' => array_values($cleanup),
            'caddy_node_ids' => $nodes, 'dns_node_ids' => ValidatedData::integerList(PrivateDnsPublicationNodes::query()->pluck('id')->all()),
            'router_node_ids' => [$host->id], 'ingress_node_ids' => array_values(array_unique($ingress)),
            'public_verifications' => $verifications];
    }

    /** @return list<Route> */
    public static function placements(Route $route): array
    {
        $placements = [$route];
        if ($route->hasPlacementTransition()) {
            $prior = clone $route;
            $prior->node_id = $route->transition_node_id;
            $prior->cluster_id = $route->transition_cluster_id;
            $prior->unsetRelations();
            $placements[] = $prior;
        }

        return $placements;
    }

    /** @return Leaf */
    private function leaf(Route $route): array
    {
        $node = $this->sites->routerNode($route);

        return ['node_id' => $node->id, 'user' => $node->user, 'home' => $this->accounts->resolve($node)->home,
            'agent_sha256' => $this->agents->pin($node), 'route_id' => $route->id,
            'domain' => $route->domain, 'scope' => "route-{$route->id}-router"];
    }
}
