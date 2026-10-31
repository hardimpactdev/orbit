<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\FleetRolloutNode;
use App\Models\Node;
use App\Models\Operation;

/** Captures one fleet-wide intent; no probes, target locks or child Operations. */
final readonly class AdmitFleetRolloutAction
{
    public function __construct(private FleetRolloutPlanAction $plan, private CliConvergePlan $cli,
        private GatewayServingHost $gateway, private StartOperationAction $start, private AgentDelivery $delivery) {}

    /** @param list<int>|null $nodeIds */
    public function execute(?int $rolloutId = null, ?array $nodeIds = null): Operation
    {
        $plan = $this->plan->execute();
        $root = $this->gateway->nodeId();
        if ($root === null || ! isset($plan['rollout']) || ($rolloutId !== null && $rolloutId !== $plan['rollout'])
            || ! in_array($plan['status'], ['running', 'completed'], true)) {
            throw new ResourceOperationException('fleet.rollout_not_ready', 'The serving release has no available fleet rollout.', 409);
        }
        $nodes = [];
        foreach ($plan['targets'] ?? [] as $target) {
            if ($nodeIds !== null && ! in_array($target['node_id'], $nodeIds, true)) {
                continue;
            }
            $node = Node::query()->with('roles')->findOrFail($target['node_id']);
            $agent = $this->delivery->artifacts()[AgentDelivery::key($node)] ?? null;
            if (! is_array($agent) || ! is_string($agent['url'] ?? null)) {
                throw new ResourceOperationException('agent.release_unavailable', 'The pinned agent has no published delivery.', 409);
            }
            $nodes[] = ['node_id' => $node->id, 'row_id' => $target['row_id'], ...$this->cli->input($node),
                'agent' => ['url' => $agent['url'], 'sha256' => $agent['sha256'], 'version' => $this->delivery->reportedVersion($node)],
                'allow_downgrade' => $plan['allow_downgrade'],
                'daytime' => $node->roles->isNotEmpty() && $node->roles->every(static fn ($role): bool => $role->role === RoleName::AppDev)];
        }
        if ($nodes === [] || ($nodeIds !== null && count($nodes) !== count($nodeIds))) {
            throw new ResourceOperationException('fleet.rollout_not_ready', 'The requested Nodes are not pending rollout members.', 409);
        }
        $operation = $this->start->execute('fleet.rollout', 'fleet_rollout', $plan['rollout'], $root, [
            'rollout_id' => $plan['rollout'], 'commit' => $plan['commit'], 'nodes' => $nodes,
            'remaining' => count($plan['targets']) - count($nodes),
            'participant_node_ids' => array_values(array_unique([$root, 209, ...array_column($nodes, 'node_id')])),
        ], 'swamp', null);
        FleetRolloutNode::query()->where('fleet_rollout_id', $plan['rollout'])->whereIn('node_id', array_column($nodes, 'node_id'))
            ->update(['started_at' => now(), 'finished_at' => null]);

        return $operation;
    }
}
