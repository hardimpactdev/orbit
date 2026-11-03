<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeNetworkReservation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Admits the same footprint kind used as a child by fleet rollout. */
final readonly class StartNodeConvergeAction
{
    public function __construct(private ManagedNodeEligibility $eligibility, private StartOperationAction $operations) {}

    public function execute(Node $node, bool $force = false, bool $firstVisit = false, ?string $requestId = null): Operation
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node has a Gateway-rendered footprint.', 422);
        }

        return app(NodeNetworkReservation::class)->hold($node, function (?Node $hub) use ($node, $force, $firstVisit, $requestId): Operation {
            $snapshot = app(NodeConvergeInput::class)->captureNative($node, $force, $firstVisit);
            // A part the Gateway cannot render refuses here; no run starts that could not apply it.
            if (is_string($snapshot['refusal'] ?? null)) {
                throw new ResourceOperationException($snapshot['refusal'], 'A footprint part cannot be rendered for this Node.', 409);
            }

            return $this->operations->execute('node.converge', 'node', $node->id, $node->id,
                ['_converge' => $snapshot, 'participant_node_ids' => array_values(array_unique([$node->id, $hub->id ?? $node->id]))], 'operator', $requestId, lockKey: 'node-converge:node:'.$node->id);
        });
    }
}
