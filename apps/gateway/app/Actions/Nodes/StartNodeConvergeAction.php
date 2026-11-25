<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Fleet\DesiredCliReleaseData;
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
use App\Domain\Tools\AptSourceTrust;
use App\Domain\Tools\ToolConvergeIntent;
use App\Models\Node;
use App\Models\Operation;

/** Admission and immutable intent only. Each phase runs unless its digest equals the Node's record (NodeConvergePhaseDigest). */
final readonly class StartNodeConvergeAction
{
    public function __construct(private ManagedNodeEligibility $eligibility, private StartOperationAction $operations) {}

    /**
     * A release converge applies only the roles with changed inputs. An automatic converge, a release converge or the full converge
     * the release pass starts for changed host inputs, is admitted by NodeWorkAdmission's rules for automatic work.
     *
     * The fleet pass supplies the release inputs and the CLI pin it read before it took the SQLite writer, so this admission
     * hashes no release file under it.
     *
     * @param  list<string>|null  $roles
     * @param  array{roles: array<int, array{role:string,digest:string}>, host: string}|null  $releaseInputs
     */
    public function execute(Node $node, bool $base = false, ?array $roles = null, ?string $requestId = null, bool $full = false, bool $release = false, bool $automatic = false,
        ?array $releaseInputs = null, ?DesiredCliReleaseData $cli = null): Operation
    {
        $automatic = $automatic || $release;
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

        return app(NodeNetworkReservation::class)->hold($node, $base, function (?Node $hub) use ($node, $base, $roles, $selected, $requestId, $full, $release, $automatic, $releaseInputs, $cli): Operation {
            $toolScope = ! $base && $roles === null;
            // Read before the admission clears the record; a holder refuses this admission, so nothing writes it in between.
            // Release admission already compares its public role digest, including the workflow and materializer.
            $fresh = $node->fresh() ?? $node;
            $recorded = app(NodeConvergePhaseDigest::class)->recorded($fresh, $full || $release);
            // A scoped converge keeps the full-scope converge record it holds now (NodeConvergePhaseDigest::held()).
            $held = $base || $roles !== null ? app(NodeConvergePhaseDigest::class)->holdable($fresh) : null;
            $operation = $this->operations->execute('node.converge', 'node', $node->id, $node->id,
                ['_converge' => ['network' => ['hub_id' => $hub?->id, 'base' => $base], 'tool_scope' => $toolScope, 'intent' => ['release_roles_only' => $release, 'automatic' => $automatic]],
                    'participant_node_ids' => array_values(array_unique([$node->id, $hub->id ?? $node->id]))],
                'operator', $requestId, lockKey: OperationStore::lockKey('node', $node->id));
            if ($operation->wasRecentlyCreated) {
                // The one write before the capture: the role that runs the Instance container branch on the Node, `app-dev` on a
                // development Node or `ci-runner` on a CI-only Node, and `app-prod` for its release bridge, gets its Instance bridge here,
                // never in the capture. A Node with neither releases an unused bridge.
                app(InstanceHostIntent::class)->prepare($node, $selected);
                $inputs = app(ReleaseNodeInputs::class);
                $snapshot = app(NodeDesiredConvergeInput::class)->capture($node, $selected, $toolScope, $operation, $release ? null : $hub, $release, $cli);
                $plans = RouteExecutionProof::objects($snapshot['intent']['roles']);
                $roleInputs = $releaseInputs === null
                    ? $inputs->roles($node, $release, $selected, renderedRoles: array_column($plans, null, 'assignment_id'))
                    : array_filter($releaseInputs['roles'], static fn (array $role): bool => in_array($role['role'], $selected, true));
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
                $tools = RouteExecutionProof::objects($snapshot['intent']['tools']);
                AptSourceTrust::suspectPlans($node->id, $tools);
                $toolSeconds = ToolConvergeIntent::phaseSeconds(count($tools));
                $snapshot['intent'] = [...$snapshot['intent'], 'automatic' => $automatic, ...($tools === [] ? [] : ['tools_seconds' => $toolSeconds])];
                $snapshot['release_inputs'] = [
                    'roles' => array_map(static fn (array $role): string => $role['digest'], $roleInputs),
                    'host' => ! $release && ! $base && $roles === null ? $releaseInputs['host'] ?? $inputs->host(node: $node) : null,
                ];
                $snapshot['intent'] = app(NodeConvergePhaseDigest::class)->stamp($snapshot['intent'], $recorded);
                if ($held !== null) {
                    $snapshot['held_digests'] = $held;
                }
                // Dispatch is deferred until this reservation transaction commits.
                $operation->input = [...$operation->input, 'run_budget_seconds' => $toolSeconds + 6700, '_converge' => [
                    ...$snapshot, 'tool_scope' => $toolScope, 'network' => ['hub_id' => $hub?->id, 'base' => $base],
                ]];
                $operation->save();
            }

            return $operation;
        });
    }
}
