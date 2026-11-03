<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Admits the same footprint kind used as a child by fleet rollout. */
final readonly class StartNodeConvergeAction
{
    public function __construct(private ManagedNodeEligibility $eligibility, private StartOperationAction $operations) {}

    public function execute(Node $node, bool $force = false, bool $firstVisit = false, ?string $requestId = null): Operation
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node has a Gateway-rendered footprint.', 422);
        }

        return DB::transaction(function () use ($node, $force, $firstVisit, $requestId): Operation {
            $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->first();
            // Hold both rows while reserving the run. Every converge for this hub takes these locks in ID order.
            Node::query()->whereKey(array_values(array_unique([$node->id, $hub->id ?? $node->id])))->orderBy('id')->lockForUpdate()->get();
            $node->refresh();
            if ($hub !== null && Operation::query()->where('kind', 'node.converge')->whereNotIn('status', OperationStatus::terminalValues())
                ->where('input->_converge->network->hub_id', $hub->id)->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Another converge owns the VPN hub.', 409);
            }
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
