<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\StartCertificateOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Resource intent only. Native Route workflows own effect order and recovery. */
final readonly class NativeRouteConvergenceIntent
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function forRoute(Route $route, array $input): array
    {
        $route->loadMissing(['targets.instance.node.roles', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
        $instances = $route->targets->sortBy('instance_id')->map(fn ($target): Instance => $target->instance);
        if (is_int($input['instance_id'] ?? null)) {
            $instances = $instances->where('id', $input['instance_id']);
            if ($instances->isEmpty()) {
                throw new ResourceOperationException('route.target_conflict', 'The Instance must be a stored Route target.', 409);
            }
        }
        $router = $route->cluster?->routerAssignment?->node;
        if ($route->isApp() && $route->cluster_id !== null && ! $router instanceof Node) {
            throw new ResourceOperationException('cluster.router_required', 'The Cluster Route requires a recorded Router.', 409);
        }
        $workloads = [];
        $certificates = [];
        foreach ($instances as $instance) {
            $scope = $route->hasWebRoot() ? "route-{$route->id}" : "app-instance-{$instance->id}";
            if (RouteCertificateStaging::workload($route)) {
                $scope = "app-instance-{$instance->id}-hostname-change";
            }
            $workloads[] = ['instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'production' => $instance->placedOnAppProd(), 'certificate_scope' => $scope];
            $certificates[] = ['node_id' => $instance->node_id, 'scope' => $scope, 'domain' => $route->domain];
        }
        if ($route->isCustomProxy() && $route->node_id !== null) {
            $certificates[] = ['node_id' => $route->node_id, 'scope' => "route-{$route->id}", 'domain' => $route->domain];
        }
        if ($router instanceof Node && array_any($workloads, fn (array $item): bool => $item['node_id'] !== $router->id)) {
            $certificates[] = ['node_id' => $router->id, 'scope' => "route-{$route->id}-router".(RouteCertificateStaging::router($route) ? '-hostname-change' : ''), 'domain' => $route->domain];
        }
        $certificates = array_map(fn (array $leaf): array => app(StartCertificateOperationAction::class)->convergenceInput(
            Node::query()->findOrFail($leaf['node_id']), $leaf['scope'], $leaf['domain']), $certificates);
        $public = new PublicRouteEligibility()->canStartActivation($route);
        $ingress = $public ? $route->cluster?->ingressAssignment?->node_id : null;
        $dns = PrivateDnsPublicationNodes::query()->orderBy('id')->pluck('id')->all();
        $nodes = array_values(array_unique(array_filter([...array_column($workloads, 'node_id'), ...array_column($certificates, 'node_id'),
            $router?->id, $ingress, $route->node_id, ...$dns], is_int(...))));
        sort($nodes);

        $workloadNodes = array_values(array_unique(array_column($workloads, 'node_id')));
        $workloadCaddy = array_values(array_unique(array_filter([...$workloadNodes, $route->node_id], is_int(...))));

        return ['route_id' => $route->id, 'status' => $route->status->value, 'domain' => $route->domain, 'publication' => $route->publication->value,
            'web_root' => $route->web_root, 'mode' => $input['mode'] ?? 'converge', 'workloads' => $workloads,
            'certificates' => array_values(array_unique($certificates, SORT_REGULAR)), 'router_node_id' => $router?->id,
            'ingress_node_id' => $ingress, 'dns_node_ids' => $dns, 'node_ids' => $nodes,
            'production' => $workloads !== [] && array_all($workloads, fn (array $item): bool => $item['production']),
            'workload_node_ids' => $workloadNodes, 'workload_caddy_node_ids' => $workloadCaddy,
            'public_node_ids' => $public ? array_values(array_unique(array_filter([$ingress, $router?->id], is_int(...)))) : [],
            'caddy_node_ids' => array_values(array_unique([...$workloadCaddy, ...array_filter([$router?->id, $ingress], is_int(...))])),
            'rollback_removals' => array_map(fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'scope', 'home', 'agent_sha256'])) + ['in_use' => false],
                array_values(array_filter($certificates, fn (array $leaf): bool => $route->hasWebRoot() && str_starts_with($leaf['scope'], 'route-'.$route->id)))),
            'public' => $public, 'sites_published' => $route->sites_published];
    }

    /** @return list<int> */
    public static function participants(Operation $operation): array
    {
        return ValidatedData::integerList(ValidatedData::object($operation->input['route_convergence'] ?? [])['node_ids'] ?? []);
    }

    /** Pure fresh public rendering, called while the native Node publication lock is held.
     * @return array<string, mixed>
     */
    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $intent = ValidatedData::object($operation->input['route_convergence'] ?? []);
        if (! in_array($callerNodeId, self::participants($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Route participant.', 403);
        }
        $route = Route::query()->findOrFail(ValidatedData::integer($intent['route_id']));
        $node = Node::query()->findOrFail($callerNodeId);
        $workloads = [];
        foreach (array_map(ValidatedData::object(...), $intent['workloads']) as $member) {
            if ($member['node_id'] !== $callerNodeId) {
                continue;
            }
            $instance = Instance::query()->findOrFail(ValidatedData::integer($member['instance_id']));
            if ($member['production']) {
                [$runtime] = app(InstanceDeploymentIntent::class)->runtimeIntent($instance, $route->status === RouteStatus::Failed ? null : $route);
                $extra = ['runtime' => $runtime, 'profile' => $runtime === null ? null : app(RemotePhpPackageManager::class)->runtimeInput($node, $instance->selected_php_version),
                    'packages' => $runtime === null ? null : app(RemotePhpPackageManager::class)->packagesOnlyInputForAppProd($node, [$instance->selected_php_version])];
            } else {
                $extra = app(RouteWorkloadInput::class)->forInstance($instance, $route, $callerNodeId);
                $extra['packages'] = app(RouteWorkloadInput::class)->packages($node, array_keys((array) $extra['php']['configurations']));
            }
            $routerId = $intent['router_node_id'];
            $lan = is_int($routerId) ? app(RouteHopInput::class)->firewall($instance, $route, Node::query()->findOrFail($routerId), $callerNodeId) : null;
            $served = null;
            if ($member['production'] && $route->hasWebRoot()) {
                $root = $instance->root ?? $instance->project->root;
                $served = ['repository' => $instance->project->repository_url, 'user' => $instance->production_user, 'home' => $instance->production_home, 'instance' => $instance->id,
                    'relative_root' => $root, 'application_suffix' => ProductionApplicationPaths::suffix($root),
                    'served' => array_map(fn (array $item): array => ['web_root' => $item['web_root'], 'directory' => $item['directory']], RouteWebRoot::servedApplications($instance, $route))];
            }
            $workloads[] = [...$member, ...$extra, 'lan' => $lan, 'served_web_roots' => $served];
        }

        $dns = null;
        if (in_array($callerNodeId, $intent['dns_node_ids'], true)) {
            $renderer = app(DevelopmentDnsConfigRenderer::class);
            $records = $renderer->render();
            $catalog = json_encode(['requesters' => $renderer->registeredRequesters(), ...$renderer->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $dns = ['node_id' => $callerNodeId, 'records' => $records, 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)];
        }

        $firewall = null;
        $role = $callerNodeId === $intent['ingress_node_id'] ? RoleName::Ingress : RoleName::AppProd;
        if ($callerNodeId === $intent['ingress_node_id'] || array_any($workloads, fn (array $item): bool => $item['production'])) {
            $rules = app(NodeFirewallRuleCatalog::class);
            $baseline = $rules->forNode($node);
            $firewall = ['desired' => array_map(fn (UfwManagedRule $rule): array => ['shape' => $rule->shape->toAgentInput(), 'replace_port' => $rule->replacePort], [$baseline[1], ...$rules->forRole($node, $role)]),
                'remove' => array_map(fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), [$baseline[0], ...$rules->retiredForRole($node, $role)]), 'enable' => false];
        }
        $probes = [];
        if ($callerNodeId === $intent['router_node_id']) {
            foreach ($route->targets()->with('instance.node')->get() as $target) {
                if ($target->instance->node_id !== $callerNodeId) {
                    $probes[] = ['protocol' => 'tls', 'agent_sha256' => app(AgentDelivery::class)->pin($node),
                        'probe' => ['address' => $target->instance->node->wireguard_ip, 'port' => 443, 'domain' => $route->domain]];
                }
            }
        }
        if ($callerNodeId === $intent['ingress_node_id'] && is_int($intent['router_node_id'])) {
            $target = Node::query()->findOrFail($intent['router_node_id']);
            $probes[] = ['protocol' => 'tls', 'agent_sha256' => app(AgentDelivery::class)->pin($node), 'probe' => ['address' => $target->wireguard_ip, 'port' => 443, 'domain' => $route->domain]];
        }

        return ['environment_fields' => $operation->input['environment_fields'] ?? [], 'caddy_publication' => CaddyPublicationPlan::forNode($node),
            'php_publication' => ['node_id' => $callerNodeId, 'agent_sha256' => app(AgentDelivery::class)->pin($node), ...app(SharedPhpPoolInput::class)->forNode($node)],
            'private_dns_publication' => $dns, 'route' => $intent, 'firewall' => $firewall, 'probes' => $probes, 'publication' => ['node_id' => $callerNodeId,
                'agent_sha256' => app(AgentDelivery::class)->pin($node), 'caddy_publication' => CaddyPublicationPlan::forNode($node),
                'php' => ['node_id' => $callerNodeId, 'agent_sha256' => app(AgentDelivery::class)->pin($node), ...app(SharedPhpPoolInput::class)->forNode($node)], 'dns' => $dns], 'workloads' => $workloads];
    }
}
