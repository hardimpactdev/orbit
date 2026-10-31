<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;

/** Immutable public Router inventory; native Swamp owns transition sequencing. */
final readonly class NativeClusterRouterIntent
{
    public function __construct(private AgentDelivery $agents,
        private NodeRoleFirewallInput $firewall, private RolePrerequisites $prerequisites, private NativeRouteCertificateInput $certificates) {}

    /** @return array<string,mixed> */
    public function forOperation(Operation $operation): array
    {
        $assignments = NodeRole::query()->with('node')->where('cluster_id', $operation->input['cluster_id'])->where('role', RoleName::Router)->orderBy('id')->get();
        $candidate = $assignments->firstWhere('id', $operation->input['candidate_role_id'] ?? null);
        if ($operation->kind === 'cluster.router_set' && ($candidate === null || $candidate->node_id !== $operation->node_id)) {
            throw new ResourceOperationException('cluster.router_transition_conflict', 'The admitted Router candidate assignment changed.', 409);
        }
        $old = $assignments->filter(static fn (NodeRole $role): bool => $role->id !== $candidate?->id);
        $routes = Route::query()->with(['targets.instance.node', 'cluster.ingressAssignment.node'])->where('cluster_id', $operation->input['cluster_id'])->orderBy('id')->get();
        $workloads = [];
        $leaves = [];
        $oldNodes = [];
        $probes = [];
        $edgeNodeIds = [];
        foreach ($old as $role) {
            $oldNodes[] = [...$this->identity($role->node), 'assignment_id' => $role->id,
                'firewall' => $this->firewall->remove($role->node, RoleName::Router), 'router_lan' => $this->firewall->routerLan($role->node, true),
                'certificate_scopes' => $routes->flatMap(static fn (Route $route): array => ["route-{$route->id}-router", "route-{$route->id}-router-hostname-change"])->all()];
        }
        foreach ($routes as $route) {
            if ($route->publication === RoutePublication::Public && $route->cluster?->ingressAssignment?->node_id !== null) {
                $edgeNodeIds[] = $route->cluster->ingressAssignment->node_id;
            }
            $remote = $route->isAnalyticsTracking();
            foreach ($route->targets as $target) {
                $node = $target->instance->node;
                if ($candidate !== null && $node->id !== $candidate->node_id) {
                    $remote = true;
                    $input = new RouteHopInput()->firewall($target->instance, $route, $candidate->node, $node->id);
                    if ($input !== null) {
                        $workloads[] = [...$this->identity($node), 'route_id' => $route->id, 'firewall' => $input];
                    }
                    if ($route->sites_published) {
                        $address = is_string($node->lan_ip) && $node->lan_ip !== '' ? $node->lan_ip : $node->wireguard_ip;
                        $probes[] = ['protocol' => 'tls', 'agent_sha256' => $this->agents->pin($candidate->node),
                            ...app(NativeCaddyPublicationInput::class)->connectionForNode($candidate->node),
                            'probe' => ['address' => $address, 'port' => 443, 'domain' => $route->domain]];
                    }
                }
                foreach ($old as $role) {
                    if ($node->id === $role->node_id || ! is_string($node->lan_ip) || $node->lan_ip === '' || ! is_string($role->node->lan_ip) || $role->node->lan_ip === '') {
                        continue;
                    }
                    $shape = new UfwRuleShape("orbit:route-{$route->id}-lan", 'allow', 'in', $role->node->lan_ip, $node->lan_ip, '443', 'tcp', null, null, 'v4');
                    $workloads[] = [...$this->identity($node), 'route_id' => $route->id, 'retire_router_id' => $role->node_id,
                        'retire_firewall' => ['desired' => [], 'remove' => [$shape->toAgentInput()], 'enable' => false]];
                }
            }
            $renders = $route->sites_published || ($route->status === RouteStatus::Pending && $route->replaces_route_id !== null && RouteCertificateStaging::reached($route, RouteReplacementStep::RouterCertificate));
            if ($candidate !== null && $remote && $renders) {
                $scope = "route-{$route->id}-router".(RouteCertificateStaging::router($route) ? '-hostname-change' : '');
                $leaves[] = $this->certificates->convergenceInput($candidate->node, $scope, $route->domain);
            }
        }
        $uniqueWorkloads = [];
        foreach ($workloads as $workload) {
            $key = $workload['route_id'].'.'.$workload['node_id'].'.'.($workload['retire_router_id'] ?? 'candidate');
            $uniqueWorkloads[$key] = $workload;
        }
        ksort($uniqueWorkloads);
        $workloads = array_values($uniqueWorkloads);
        $candidateInput = $candidate === null ? null : [...$this->identity($candidate->node), 'assignment_id' => $candidate->id,
            'active' => $candidate->status === LifecycleStatus::Active, 'caddy_package' => AgentCaddyPackage::input(),
            'prerequisites' => $this->prerequisiteInput($candidate->node),
            'firewall' => $this->firewall->converge($candidate->node, RoleName::Router), 'router_lan' => $this->firewall->routerLan($candidate->node), 'probes' => $probes];

        return ['cluster_id' => $operation->input['cluster_id'], 'candidate' => $candidateInput,
            'noop' => $candidate?->status === LifecycleStatus::Active && $old->isEmpty(), 'old_nodes' => $oldNodes,
            'leaves' => $leaves, 'workloads' => $workloads, 'route_ids' => $routes->modelKeys(),
            'edge_node_ids' => array_values(array_unique($edgeNodeIds)),
            'dns_node_ids' => PrivateDnsPublicationNodes::query()->orderBy('id')->pluck('id')->all()];
    }

    /** @return array<string,mixed> */
    private function prerequisiteInput(Node $node): array
    {
        $input = $this->prerequisites->input($node, RoleName::Router,
            new ManagedUserAccount($node->user, NodeConvergeInput::GroupToken, NodeConvergeInput::HomeToken));
        unset($input['group'], $input['home']);

        return $input;
    }

    /** @return array{node_id:int,user:string,agent_sha256:string} */
    private function identity(Node $node): array
    {
        if ($node->platform !== 'linux' || ! LinuxUserName::isValid($node->user)) {
            throw new ResourceOperationException('cluster.router_node_invalid', 'Router participants require a configured Linux Node account.', 422);
        }

        return ['node_id' => $node->id, 'user' => $node->user, 'agent_sha256' => $this->agents->pin($node),
            ...app(NativeCaddyPublicationInput::class)->connectionForNode($node)];
    }
}
