<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeConvergeScope;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\NodeNetworkReservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Admission and immutable intent only. Every new attempt runs every phase. */
final readonly class StartNodeConvergeAction
{
    public function __construct(private ManagedNodeEligibility $eligibility, private StartOperationAction $operations) {}

    /** @param list<string>|null $roles */
    public function execute(Node $node, bool $base = false, ?array $roles = null, ?string $requestId = null): Operation
    {
        $verifier = $node->agent_secret_hash;
        if (! $this->eligibility->allows($node) || ! is_string($verifier) || preg_match('/\A[0-9a-f]{64}\z/D', $verifier) !== 1) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node can converge.', 422);
        }
        $selected = NodeConvergeScope::roles($node, $base, $roles);
        if (($existing = app(OperationIdempotency::class)->replay('node.converge')) !== null) {
            return $existing;
        }

        return app(NodeNetworkReservation::class)->hold($node, function (?Node $hub) use ($node, $base, $roles, $selected, $requestId): Operation {
            $operation = $this->operations->execute('node.converge', 'node', $node->id, $node->id,
                ['_converge' => ['network' => ['hub_id' => $hub?->id]],
                    'participant_node_ids' => array_values(array_unique([$node->id, $hub->id ?? $node->id]))],
                'operator', $requestId, lockKey: OperationStore::lockKey('node', $node->id));
            if ($operation->wasRecentlyCreated) {
                // The one write before the capture: a build-list app-dev Node gets its Instance bridge here, never in the capture.
                if (in_array(RoleName::AppDev->value, $selected, true)) {
                    app(InstanceHostIntent::class)->prepare($node);
                }
                $snapshot = app(NodeDesiredConvergeInput::class)->capture($node, $selected, ! $base && $roles === null, $operation, $hub);
                // Dispatch is deferred until this reservation transaction commits.
                $operation->input = [...$operation->input, '_converge' => [
                    ...$snapshot, 'network' => ['hub_id' => $hub?->id],
                ]];
                $operation->save();
            }

            return $operation;
        });
    }
}
