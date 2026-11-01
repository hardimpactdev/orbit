<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\PublicProcessRouteFirewall;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Pure admission data for exact Node effects and probes. No executor command. */
final readonly class RouteNodeEffectCommand
{
    public function __construct(private IngressSiteRepository $sites, private PublicProcessRouteFirewall $processFirewall) {}

    public function assertLanRemoval(Operation $operation): void
    {
        if ($operation->kind !== 'route.lan_remove' || $operation->node_id === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A recorded Route LAN withdrawal is required.', 422);
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $route = Route::query()->with('targets.instance')->findOrFail(ValidatedData::integer($operation->input['route_id']));
        if ($route->sites_published || (! $route->targets->contains(static fn ($target): bool => $target->instance->node_id === $node->id)
            && $route->node_id !== $node->id && $route->generation_basis_node_id !== $node->id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Withdraw stored Route site intent before cleaning its recorded workload Node.', 422);
        }
    }

    /**
     * Network checks capture only the admitted protocol and endpoint.
     *
     * @return array{protocol: string, probe: array<string, mixed>}
     */
    public function networkVerification(Operation $operation): array
    {
        if (! in_array($operation->kind, RouteOperationProjection::Kinds, true) || $operation->node_id === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A Route network verification is required.', 422);
        }
        $route = Route::query()->with('targets.instance')->findOrFail(ValidatedData::integer($operation->input['route_id']));
        if ($operation->kind === 'route.workload_verify') {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
            if (! $route->targets->contains('instance_id', $instance->id) && ! new RoutePreparedWorkloads()->contains($operation, $route, $instance)) {
                throw new ResourceOperationException('route.target_conflict', 'The workload must remain a stored Route target.', 409);
            }
            $this->router($route, $operation->node_id);
            $target = $instance->node;
        } else {
            $ingress = $this->sites->ingressNode($route);
            if ($ingress->id !== $operation->node_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The public verification belongs to the stored Ingress Node.', 422);
            }
            if ($route->isCustomProxy()) {
                $process = $route->customProxy?->process;
                if ($process === null || ! $this->processFirewall->matches($route, $ingress)) {
                    throw new ResourceOperationException('route.upstream_firewall_mismatch', 'The Process Node must allow the stored Ingress to reach its listener.', 409);
                }
                $upstream = new CustomProxyProcessListener()->resolvePublic($process, $ingress);

                return ['protocol' => 'http', 'probe' => ['address' => $upstream->host, 'port' => $upstream->port, 'domain' => $route->domain]];
            }
            $target = $this->sites->routerNode($route);
        }
        $address = $target->wireguard_ip;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('route.workload_address_missing', 'Network verification requires its stored WireGuard endpoint.', 409);
        }

        return ['protocol' => 'tls', 'probe' => ['address' => $address, 'port' => 443, 'domain' => $route->domain]];
    }

    private function router(Route $route, int $nodeId): Node
    {
        $route->loadMissing('cluster.routerAssignment.node');
        if ($route->cluster?->routerAssignment?->node_id !== $nodeId && ($route->cluster_id === null
            || ! Node::query()->whereKey($nodeId)->whereHas('roles', fn ($query) => $query
                ->where('cluster_id', $route->cluster_id)->where('role', RoleName::Router)->whereIn('status', ['provisioning', 'failed']))->exists())) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The probe or firewall requires the recorded current or candidate Router.', 422);
        }

        return Node::query()->findOrFail($nodeId);
    }
}
