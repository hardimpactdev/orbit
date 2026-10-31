<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Collection;

/** Stored intent and rendered data only. The owning Swamp DAG chooses order and placement. */
final readonly class RouteWithdrawalArtifacts
{
    public const string User = '__ORBIT_ACCOUNT_USER__';

    public const string Group = '__ORBIT_ACCOUNT_GROUP__';

    public const string Home = '__ORBIT_ACCOUNT_HOME__';

    public function __construct(private SharedPhpPoolInput $php, private NodeCaddyfileRenderer $caddy,
        private DevelopmentDnsConfigRenderer $dns, private DevelopmentSiteRepository $sites, private AgentDelivery $agents) {}

    /** The caller retains Route associations until the parent projects successful withdrawal.
     * @param  Collection<int, Route>  $routes
     * @return array<string, mixed>
     */
    public function forDevelopmentRemoval(Instance $instance, Collection $routes): array
    {
        $instance->loadMissing(['node', 'project']);
        if ($instance->status !== InstanceState::Removing || ! $instance->placedOnAppDev()) {
            throw new ResourceOperationException('route.withdrawal_invalid', 'A removing development Instance is required.', 409);
        }
        $routeIds = $routes->pluck('id')->unique()->sort()->values()->all();
        if ($instance->routes()->orderBy('routes.id')->pluck('routes.id')->all() !== $routeIds) {
            throw new ResourceOperationException('route.withdrawal_invalid', 'Capture every attached Route before withdrawing the Instance.', 409);
        }
        $nodes = [$instance->node_id => $instance->node];
        $scopes = [$instance->node_id => ["app-instance-{$instance->id}"]];
        $lan = [];
        $ids = [];
        foreach ($routes as $route) {
            $route->loadMissing(['targets', 'cluster.routerAssignment.node', 'transitionCluster.routerAssignment.node', 'node', 'transitionNode', 'generationBasisNode']);
            if ($route->kind !== RouteKind::App || $route->project_id !== $instance->project_id
                || $route->publication !== RoutePublication::Private || $route->status !== RouteStatus::Retiring || $route->sites_published
                || $route->targets->count() !== 1 || ! $route->targets->contains('instance_id', $instance->id)) {
                throw new ResourceOperationException('route.withdrawal_invalid', 'Keep only this Instance target attached and record private Route withdrawal before rendering.', 409);
            }
            $ids[] = $route->id;
            $lan[$instance->node_id][] = $route->id;
            if ($route->hasWebRoot()) {
                $scopes[$instance->node_id][] = "route-{$route->id}";
            }
            if ($route->hasPlacementTransition() || $route->replaces_route_id !== null) {
                $scopes[$instance->node_id][] = "app-instance-{$instance->id}-hostname-change";
            }
            foreach ([$route->node, $route->transitionNode, $route->generationBasisNode] as $node) {
                if ($node instanceof Node) {
                    $nodes[$node->id] = $node;
                }
            }
            if ($route->generationBasisNode instanceof Node) {
                $lan[$route->generationBasisNode->id][] = $route->id;
            }
            foreach ([$route->cluster?->routerAssignment?->node, $route->transitionCluster?->routerAssignment?->node] as $router) {
                if ($router instanceof Node) {
                    $nodes[$router->id] = $router;
                    $scopes[$router->id][] = "route-{$route->id}-router";
                    $scopes[$router->id][] = "route-{$route->id}-router-hostname-change";
                }
            }
        }
        sort($ids);
        ksort($nodes);
        $artifacts = [];
        foreach ($nodes as $node) {
            $identity = $this->node($node);
            $render = $this->caddy->render($node);
            if (! $render->buildable()) {
                throw new ResourceOperationException('caddy.render_failed', 'The stored Node Caddy configuration cannot be rendered.', 409);
            }
            $certificates = array_values(array_unique($scopes[$node->id] ?? []));
            sort($certificates);
            foreach ($certificates as $scope) {
                if ($this->sites->forNode($node)->contains(static fn ($site): bool => $site->loadsCertificate($scope))) {
                    throw new ResourceOperationException('app-dev.certificate_in_use', 'A stored Caddy site still uses a withdrawing certificate.', 409);
                }
            }
            $routeIds = array_values(array_unique($lan[$node->id] ?? []));
            sort($routeIds);
            $artifacts[] = [...$identity, 'caddy' => CaddyPublicationPlan::input($render, $node->id),
                'certificate_scopes' => $certificates, 'lan_route_ids' => $routeIds];
        }
        $catalog = json_encode(['requesters' => $this->dns->registeredRequesters(), ...$this->dns->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $dns = [];
        foreach ($ids === [] ? [] : PrivateDnsPublicationNodes::query()->get() as $node) {
            $dns[] = [...$this->node($node), 'records' => $this->dns->render(), 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)];
        }

        return ['schema' => 'route.withdrawal.v1', 'instance_id' => $instance->id, 'route_ids' => $ids,
            'php' => [...$this->node($instance->node), ...$this->php->forAccount($instance->node, new ManagedUserAccount(self::User, self::Group, self::Home))],
            'nodes' => $artifacts, 'dns' => $dns];
    }

    /** @return array{node_id:int, user:string, agent_sha256:string} */
    private function node(Node $node): array
    {
        if ($node->platform !== 'linux' || $node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('route.withdrawal_node_unavailable', 'A withdrawing Node must remain active and run Linux.', 409);
        }

        return ['node_id' => $node->id, 'user' => $node->user, 'agent_sha256' => $this->agents->pin($node)];
    }
}
