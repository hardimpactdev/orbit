<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\PublicProcessRouteFirewall;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Pure preparation for one narrow Node effect. All addresses come from Gateway placement. */
final readonly class RouteNodeEffectCommand implements OperationGatewayCommand
{
    public function __construct(private RouteHopInput $hops, private SharedPhpPoolInput $php,
        private IngressSiteRepository $sites, private PublicProcessRouteFirewall $processFirewall,
        private NodeFirewallRuleCatalog $firewall) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'], 'properties' => [
            'action' => ['enum' => ['prepare', 'prove']], 'route_id' => ['type' => 'integer', 'minimum' => 1],
            'instance_id' => ['type' => 'integer', 'minimum' => 1], 'router_node_id' => ['type' => 'integer', 'minimum' => 1],
            'transfer_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 36]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (! in_array($operation->kind, RouteNodeEffectExecution::Kinds, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This kind has no Route Node effect.', 422);
        }
        $operation->refresh();
        if ($input['action'] === 'prove') {
            RouteExecutionProof::preceding($operation, app(RouteNodeEffectExecution::class)->context($operation)['descriptors'], 'node-effect-proof');

            return ['ready' => true];
        }
        if (! is_array($operation->state['route_effect_plan'] ?? null)) {
            try {
                $plan = $this->prepare($operation);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'route.upstream_firewall_mismatch') {
                    throw $exception;
                }

                return ['error_code' => $exception->errorCode];
            }
            $operation->update(['state' => [...($operation->state ?? []), 'route_effect_plan' => $plan]]);
        }

        return ['prepared' => true];
    }

    /** @return array<string, mixed> */
    public function prepare(Operation $operation): array
    {
        $node = Node::query()->findOrFail($operation->node_id);
        if (str_starts_with($operation->kind, 'route.transfer_')) {
            $transfer = InstanceTransfer::query()->findOrFail(ValidatedData::string($operation->input['transfer_id']));
            if ($transfer->cutover_at === null || $node->id !== $transfer->source_node_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Transfer cleanup belongs to its recorded source after cutover.', 422);
            }
            if ($operation->kind === 'route.transfer_php_withdraw') {
                return ['php' => $this->php->forNode($node)];
            }
            $routeId = ValidatedData::integer($operation->input['route_id']);
            if (! in_array($routeId, [$transfer->source_route_id, ...($transfer->web_root_route_ids ?? [])], true)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The transfer did not own this source Route.', 422);
            }

            return ['command' => 'firewall.route-lan', 'input' => ['operation' => 'remove', 'route_id' => $routeId]];
        }
        $route = Route::query()->with(['targets.instance', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])->findOrFail(ValidatedData::integer($operation->input['route_id']));
        if ($operation->kind === 'route.router_lan_retire') {
            $router = Node::query()->findOrFail(ValidatedData::integer($operation->input['router_node_id']));
            if ($route->cluster_id === null || ! $route->targets->contains(static fn ($target): bool => $target->instance->node_id === $node->id)
                || ! $router->roles()->where('cluster_id', $route->cluster_id)->where('role', RoleName::Router)->exists()) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Retire only a recorded Router allowance on its Route workload Node.', 422);
            }
            if ($router->id === $node->id || ! is_string($router->lan_ip) || $router->lan_ip === '' || ! is_string($node->lan_ip) || $node->lan_ip === '') {
                return ['skip' => true];
            }
            $shape = new UfwRuleShape("orbit:route-{$route->id}-lan", 'allow', 'in', $router->lan_ip, $node->lan_ip, '443', 'tcp', null, null, 'v4');

            return ['command' => 'firewall.rules', 'input' => ['desired' => [], 'remove' => [$shape->toAgentInput()], 'enable' => false]];
        }
        if ($operation->kind === 'route.workload_firewall') {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
            if (! $instance->placedOnAppProd() || $instance->node_id !== $node->id || ! $route->targets->contains('instance_id', $instance->id)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Production firewall intent requires its stored Route workload Node.', 422);
            }

            return $this->roleFirewall($node, RoleName::AppProd);
        }
        if ($operation->kind === 'route.lan_converge') {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
            if (! $route->targets->contains('instance_id', $instance->id) && ! new RoutePreparedWorkloads()->contains($operation, $route, $instance)) {
                throw new ResourceOperationException('route.target_conflict', 'The workload must remain a stored Route target.', 409);
            }
            $routerId = ValidatedData::integer($operation->input['router_node_id']);
            $router = $this->router($route, $routerId);
            $input = $this->hops->firewall($instance, $route, $router, $node->id);

            return $input === null ? ['skip' => true] : ['command' => 'firewall.route-lan', 'input' => $input];
        }
        if (in_array($operation->kind, ['route.lan_remove', 'route.shared_php_withdraw'], true)) {
            if ($route->sites_published || (! $route->targets->contains(static fn ($target): bool => $target->instance->node_id === $node->id)
                && $route->node_id !== $node->id && $route->generation_basis_node_id !== $node->id)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Withdraw stored Route site intent before cleaning its recorded workload Node.', 422);
            }

            return $operation->kind === 'route.lan_remove' ? ['command' => 'firewall.route-lan', 'input' => ['operation' => 'remove', 'route_id' => $route->id]] : ['php' => $this->php->forNode($node)];
        }
        $ingress = $this->sites->ingressNode($route);
        if ($ingress->id !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The public effect belongs to the stored Ingress Node.', 422);
        }
        if ($operation->kind === 'route.public_firewall') {
            return $this->roleFirewall($node, RoleName::Ingress);
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This kind has no retained Route Node executor.', 422);
    }

    /**
     * Network checks run on the control workers; placement validation stays shared with the retained path.
     *
     * @return array{command: string, input: array<string, mixed>}
     */
    public function networkVerification(Operation $operation): array
    {
        if (! in_array($operation->kind, RouteVerificationProjection::Kinds, true) || $operation->node_id === null) {
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

                return ['command' => 'route.verify-http', 'input' => ['address' => $upstream->host, 'port' => $upstream->port, 'domain' => $route->domain]];
            }
            $target = $this->sites->routerNode($route);
        }
        $address = $target->wireguard_ip;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('route.workload_address_missing', 'Network verification requires its stored WireGuard endpoint.', 409);
        }

        return ['command' => 'route.verify-certificate', 'input' => ['address' => $address, 'port' => 443, 'domain' => $route->domain]];
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

    /** @return array<string, mixed> */
    private function roleFirewall(Node $node, RoleName $role): array
    {
        $baseline = $this->firewall->forNode($node);
        $rules = [$baseline[1], ...$this->firewall->forRole($node, $role)];

        return ['command' => 'firewall.rules', 'input' => [
            'desired' => array_map(static fn (UfwManagedRule $rule): array => ['shape' => $rule->shape->toAgentInput(), 'replace_port' => $rule->replacePort], $rules),
            'remove' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), [$baseline[0], ...$this->firewall->retiredForRole($node, $role)]), 'enable' => false]];
    }
}
