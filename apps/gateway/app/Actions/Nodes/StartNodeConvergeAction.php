<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeConvergePhaseDigest;
use App\Domain\Nodes\NodeConvergeScope;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\NodeNetworkReservation;
use App\Domain\Nodes\NodeRoleUnits;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Admission and immutable intent only. Each phase runs unless its digest equals the Node's record (NodeConvergePhaseDigest). */
final readonly class StartNodeConvergeAction
{
    public function __construct(private ManagedNodeEligibility $eligibility, private StartOperationAction $operations) {}

    /** @param list<string>|null $roles */
    public function execute(Node $node, bool $base = false, ?array $roles = null, ?string $requestId = null, bool $full = false, bool $release = false): Operation
    {
        $verifier = $node->agent_secret_hash;
        if (! $this->eligibility->allows($node) || ! is_string($verifier) || preg_match('/\A[0-9a-f]{64}\z/D', $verifier) !== 1) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node can converge.', 422);
        }
        if ($release && ($base || $roles === null)) {
            throw new ResourceOperationException('fleet.role_scope_invalid', 'Automatic convergence requires an explicit role scope.', 422);
        }
        $selected = NodeConvergeScope::roles($node, $base, $roles);
        if (($existing = app(OperationIdempotency::class)->replay('node.converge')) !== null) {
            return $existing;
        }

        return app(NodeNetworkReservation::class)->hold($node, $base, function (?Node $hub) use ($node, $base, $roles, $selected, $requestId, $full, $release): Operation {
            // Read before the admission clears the record; a holder refuses this admission, so nothing writes it in between.
            // Release admission already compares its public role digest, including the workflow and materializer.
            $recorded = app(NodeConvergePhaseDigest::class)->recorded($node->fresh() ?? $node, $full || $release);
            $operation = $this->operations->execute('node.converge', 'node', $node->id, $node->id,
                ['_converge' => ['network' => ['hub_id' => $hub?->id, 'base' => $base], 'intent' => ['release_roles_only' => $release]],
                    'participant_node_ids' => array_values(array_unique([$node->id, $hub->id ?? $node->id]))],
                'operator', $requestId, lockKey: OperationStore::lockKey('node', $node->id));
            if ($operation->wasRecentlyCreated) {
                // The one write before the capture: the role that runs the Instance container branch on the Node, `app-dev` on a
                // development Node or `ci-runner` on a CI-only Node, gets its Instance bridge here, never in the capture.
                $host = app(InstanceHostIntent::class)->role($node);
                if ($host !== null && in_array($host->value, $selected, true)) {
                    app(InstanceHostIntent::class)->prepare($node);
                }
                $inputs = app(ReleaseNodeInputs::class);
                $snapshot = app(NodeDesiredConvergeInput::class)->capture($node, $selected, ! $base && $roles === null, $operation, $release ? null : $hub, $release);
                $plans = RouteExecutionProof::objects($snapshot['intent']['roles']);
                $roleInputs = $inputs->roles($node, $release, $selected, renderedRoles: array_column($plans, null, 'assignment_id'));
                if ($release && count($roleInputs) !== count($selected)) {
                    throw new ResourceOperationException('fleet.role_scope_invalid', 'Automatic convergence requires active assignments.', 422);
                }
                if ($release) {
                    $intent = $snapshot['intent'];
                    $intent['release_roles_only'] = true;
                    $intent['role_units'] = NodeRoleUnits::for($node, $selected);
                    if (array_intersect($selected, array_keys(NodeExecutorInput::RoleFamilies)) === []) {
                        unset($intent['executor']);
                    }
                    $snapshot['intent'] = $intent;
                }
                $snapshot['release_inputs'] = [
                    'roles' => array_map(static fn (array $role): string => $role['digest'], $roleInputs),
                    'host' => ! $release && ! $base && $roles === null ? $inputs->host() : null,
                ];
                $snapshot['intent'] = app(NodeConvergePhaseDigest::class)->stamp($snapshot['intent'], $recorded);
                // Dispatch is deferred until this reservation transaction commits.
                $operation->input = [...$operation->input, '_converge' => [
                    ...$snapshot, 'network' => ['hub_id' => $hub?->id, 'base' => $base],
                ]];
                $operation->save();
            }

            return $operation;
        });
    }
}
