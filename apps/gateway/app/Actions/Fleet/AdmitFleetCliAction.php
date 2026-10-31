<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** One native root, whether convergence targets one Node or the whole fleet. */
final readonly class AdmitFleetCliAction
{
    public function __construct(private StartOperationAction $start, private CliConvergePlan $plan,
        private FleetRolloutMembership $membership, private GatewayServingHost $gateway) {}

    public function execute(Node $node, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        return $this->admit([$node], $node->id, $triggeredBy, $requestId);
    }

    /** @param list<int>|null $nodeIds */
    public function fleet(?array $nodeIds = null): Operation
    {
        $root = $this->gateway->nodeId();
        if ($root === null) {
            throw new ResourceOperationException('fleet.cli_plan_failed', 'The serving Gateway has no recorded Node.', 409);
        }
        $nodes = $nodeIds === null ? $this->membership->members() : Node::query()->whereIn('id', $nodeIds)->with('roles')->orderBy('id')->get()->all();

        return $this->admit($nodes, $root, 'swamp', null);
    }

    /** @param list<Node> $nodes */
    private function admit(array $nodes, int $root, ?string $triggeredBy, ?string $requestId): Operation
    {
        return $this->start->execute('fleet.cli_converge', 'fleet', $root, $root, [
            'nodes' => array_map(fn (Node $node): array => ['node_id' => $node->id, ...$this->plan->input($node), 'agent' => null,
                'allow_downgrade' => false, 'daytime' => true], $nodes),
            'participant_node_ids' => array_values(array_unique([$root, 209, ...array_map(static fn (Node $node): int => $node->id, $nodes)])),
        ], $triggeredBy, $requestId);
    }
}
