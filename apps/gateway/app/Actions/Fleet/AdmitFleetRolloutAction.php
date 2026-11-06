<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Captures one fleet-wide intent for the planned Nodes; no probes or child Operations. */
final readonly class AdmitFleetRolloutAction
{
    public function __construct(private FleetRolloutPlanAction $plan, private CliConvergePlan $cli,
        private GatewayServingHost $gateway, private StartOperationAction $start, private AgentDelivery $delivery) {}

    /** @param list<int>|null $nodeIds  Planned Nodes to visit; all planned Nodes when null. */
    public function execute(?array $nodeIds = null): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay('fleet.rollout')) !== null) {
            return $replay;
        }

        return DB::transaction(fn (): Operation => $this->admit($nodeIds));
    }

    /** @param list<int>|null $nodeIds */
    private function admit(?array $nodeIds): Operation
    {
        $plan = $this->plan->execute();
        $root = $this->gateway->nodeId();
        if ($root === null || $plan['status'] !== 'ready' || ! isset($plan['commit'], $plan['targets'])) {
            throw new ResourceOperationException('fleet.rollout_not_ready', 'The serving release has no available fleet rollout.', 409);
        }
        $targets = $nodeIds === null ? $plan['targets'] : array_values(array_intersect($plan['targets'], $nodeIds));
        if ($targets === [] || ($nodeIds !== null && count($targets) !== count($nodeIds))) {
            throw new ResourceOperationException('fleet.rollout_not_ready', 'The requested Nodes need no rollout visit now.', 409);
        }
        // The lock key below closes the race between admissions; this check also covers a run admitted without one.
        if (Operation::query()->where('kind', 'fleet.rollout')->whereIn('status', ['queued', 'running', 'waiting'])->exists()) {
            throw new ResourceOperationException('fleet.rollout_busy', 'A fleet rollout is already running.', 409);
        }
        $nodes = [];
        foreach ($targets as $id) {
            $node = Node::query()->with('roles')->findOrFail($id);
            $agent = $this->delivery->artifacts()[AgentDelivery::key($node)] ?? null;
            if (! is_array($agent) || ! is_string($agent['url'] ?? null)) {
                throw new ResourceOperationException('agent.release_unavailable', 'The pinned agent has no published delivery.', 409);
            }
            $nodes[] = ['node_id' => $node->id, ...$this->cli->fleetInput($node),
                'agent' => ['url' => $agent['url'], 'sha256' => $agent['sha256'], 'version' => $this->delivery->reportedVersion($node)],
                'daytime' => $node->roles->isNotEmpty() && $node->roles->every(static fn ($role): bool => $role->role === RoleName::AppDev)];
        }

        // Its steps run on the Swamp Node and read and record this intent, so that Node owns it. One rollout runs at a time.
        return $this->start->execute('fleet.rollout', 'fleet', $root, SwampNode::node()->id, [
            'commit' => $plan['commit'], 'nodes' => $nodes,
            'participant_node_ids' => array_values(array_unique([$root, SwampNode::id(), ...$targets])),
        ], 'swamp', null, lockKey: OperationStore::lockKey('fleet_rollout', $root));
    }
}
