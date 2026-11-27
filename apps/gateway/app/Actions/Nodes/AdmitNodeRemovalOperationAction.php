<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Engine\EngineTargetFence;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;

/** Admission records intent; the209 native root owns every effect and returns one terminal result. */
final readonly class AdmitNodeRemovalOperationAction
{
    public function __construct(private StartOperationAction $operations, private NodeRemovalPolicy $policy, private NodeRemovalPlan $plan) {}

    public function execute(Node $node, Node $caller, bool $offline = false, bool $force = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        if (($existing = app(OperationIdempotency::class)->replay('node.remove')) !== null) {
            return $existing;
        }
        if ($deleted) {
            throw new ResourceOperationException('node.removal_owner_required', 'Remove a deleted Node inside its owning native workflow.', 422);
        }
        $this->consent($node, $offline, $force);
        $this->policy->protected($node, $caller);
        $this->policy->resources($node, $offline);

        return DB::transaction(function () use ($node, $caller, $offline, $force, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->status === LifecycleStatus::Removing) {
                throw new ResourceOperationException('node.removal_busy', 'This Node already has an admitted removal.', 409);
            }
            $this->consent($node, $offline, $force);
            $this->policy->protected($node, $caller);
            $this->policy->resources($node, $offline);
            // Its records step deletes every Process the Node owns (NodeRemovalRecords::remove).
            EngineTargetFence::processesFree(Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->pluck('id')->all());
            $snapshot = $this->plan->build($node, $offline);
            $execution = app(NodeRemovalInput::class)->capture($snapshot);

            return $this->operations->execute('node.remove', 'node', $node->id, $node->id,
                ['offline' => $offline, 'force' => $force, '_node_removal' => $snapshot,
                    'intent' => $execution['intent'], 'execution_secrets' => [],
                    'participant_node_ids' => $execution['participant_node_ids']], 'operator', $requestId);
        });
    }

    private function consent(Node $node, bool $offline, bool $force): void
    {
        if (($offline || $node->roles()->exists()) && ! $force) {
            throw new ResourceOperationException('node.confirmation_required', 'Offline or role-shedding removal requires explicit force confirmation.', 422);
        }
    }
}
