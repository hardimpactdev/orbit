<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Routes\NativeRouteRemovalPublicInput;
use App\Domain\Routes\RouteHopInput;
use App\Domain\Routes\SharedPhpPoolInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Pure public captures for a participant selected by the shared authenticated public-view boundary. */
final readonly class NodeRemovalPublicInput
{
    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function participant(array $snapshot, Node $participant, bool $restoring = false, ?Operation $operation = null): array
    {
        $context = Node::query()->findOrFail(ValidatedData::integer($snapshot['node_id']));
        if ($context->name !== $snapshot['name']) {
            throw new ResourceOperationException('node.removal_owner_mismatch', 'The admitted removal no longer owns this Node.', 422);
        }
        $pin = app(AgentDelivery::class)->pin($participant);
        $base = ['schema_version' => 1, 'removal_node_id' => $context->id,
            'node_id' => $participant->id, 'agent_sha256' => $pin];
        $routePlan = $snapshot['route_removal'] ?? [];
        $routeNodes = is_array($routePlan) && is_array($routePlan['nodes'] ?? null) ? $routePlan['nodes'] : [];
        $routeMember = array_find($routeNodes, static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $participant->id);
        $workloads = [];
        if ($routeMember !== null && (($routeMember['production_instance_ids'] ?? []) !== [] || ($routeMember['url_instance_ids'] ?? []) !== [])) {
            if ($operation === null) {
                throw new ResourceOperationException('node.removal_unavailable', 'Route workload cleanup requires its owning native intent.', 422);
            }
            $workloads = app(NativeRouteRemovalPublicInput::class)->forNode($operation, $participant->id)['workloads'];
        }
        $fields = $operation?->input['environment_fields'] ?? [];
        $lan = [];
        if ($restoring && is_array($routeMember)) {
            foreach (ValidatedData::integerList($routeMember['lan_route_ids']) as $routeId) {
                $route = Route::query()->with(['targets.instance', 'cluster.routerAssignment.node'])->findOrFail($routeId);
                $router = $route->cluster?->routerAssignment?->node;
                if ($router !== null) {
                    foreach ($route->targets as $target) {
                        if ($target->instance->node_id === $participant->id) {
                            $input = app(RouteHopInput::class)->firewall($target->instance, $route, $router, $participant->id);
                            if ($input !== null) {
                                $lan[] = $input;
                            }
                        }
                    }
                }
            }
        }
        $routeRemoval = $routeMember === null ? null : ['member' => $routeMember,
            'workloads' => $workloads, 'restore_lan' => $lan, 'environment_field' => is_array($fields) ? ($fields[(string) $participant->id] ?? null) : null,
            'publication' => ['caddy_publication' => CaddyPublicationPlan::forNode($participant),
                'php_publication' => ['node_id' => $participant->id, 'agent_sha256' => $pin,
                    ...app(SharedPhpPoolInput::class)->forAccount($participant, NodeRemovalInput::account($participant))]],
            'ingress_firewall' => ($routeMember['ingress_firewall'] ?? false)
                ? app(NodeRoleFirewallInput::class)->converge($participant, RoleName::Ingress) : null];
        if ($participant->is($context) && $snapshot['machine_deleted'] === false) {
            return [...$base, 'network' => null, 'residue' => null, 'dns' => null, 'route_removal' => $routeRemoval];
        }
        $admitted = collect(is_array($snapshot['participants']) ? $snapshot['participants'] : [])->first(
            static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $participant->id);
        if (! is_array($admitted)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is outside the admitted removal inventory.', 403);
        }

        return [...$base, 'network' => $admitted['peer'] ? app(NodeNetworkParticipantInput::class)->capture($participant, $context, ! $restoring) : null,
            'residue' => $admitted['residue'] ? app(NodeConvergeInput::class)->residue($participant) : null,
            'dns' => $admitted['dns'] ? app(NodeConvergeInput::class)->dns() : null,
            'route_removal' => $routeRemoval];
    }
}
