<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\NodeTaskVmLifecycleOwnership;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Claims removal without dropping a Route source relation or changing any serving Node. */
final readonly class AdmitNodeRemovalOperationAction
{
    public function __construct(private StartOperationAction $operations, private OperationKinds $kinds, private OperationIdempotency $idempotency,
        private OperationStore $store, private NodeRemovalPolicy $policy, private NodeRemovalPlan $plan, private NodeReachabilityProbe $reachability) {}

    public function execute(Node $node, Node $caller, bool $offline = false, bool $force = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.remove')) !== null) {
            return $existing;
        }
        if (! is_array($this->kinds->definition('node.remove'))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Node removal is waiting for its complete execution contract.', 503);
        }
        if (app(ProcessSwampSafety::class)->requiresSynchronous($node)) {
            throw new ResourceOperationException('node.executor_dependency', 'Executor Nodes require synchronous removal recovery.', 409);
        }
        if ($deleted || $this->idempotency->parent !== null) {
            app(NodeTaskVmLifecycleOwnership::class)->removal($node, $deleted);
        }
        $this->policy->protected($node, $caller);
        $unreachable = $offline && ($deleted || $this->reachability->degradation($node) !== null);
        if ($unreachable && ! $force) {
            throw new ResourceOperationException('node.confirmation_required', 'Removing an unreachable Node sheds roles and resources. Use --force.', 422);
        }

        return DB::transaction(function () use ($node, $caller, $offline, $unreachable, $force, $deleted, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $this->policy->protected($node, $caller);
            $this->policy->resources($node, $unreachable);
            $operation = $this->operations->execute('node.remove', 'node', $node->id, $node->id,
                ['offline' => $offline, 'force' => $force, 'deleted' => $deleted && $offline, 'caller_node_id' => $caller->id], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $steps = $this->plan->build($node, $unreachable);
            $intents = [];
            foreach ($steps as $step) {
                if ($step['type'] === 'child') {
                    $intents[] = array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input']));
                }
            }
            $intents[] = ['key' => 'remove-restore', 'kind' => 'node.removal_restore', 'node_id' => $node->id, 'input' => []];
            $routeRows = $this->policy->routeRows($node);
            $roles = $node->roles()->orderBy('role')->get()->map(static fn ($role): string => $role->role->value)->all();
            $mac = $node->platform === 'macos';
            $result = ['id' => $node->id, 'name' => $node->name, 'removed' => true, 'wireguard_peer_removed' => $node->wireguard_public_key !== null,
                'dns_records_removed' => true, 'degradation' => $unreachable ? 'unreachable' : null, 'roles_shed' => $unreachable ? $roles : [],
                'retained_on_node' => $mac ? ['user', 'package-managers', 'host-wireguard'] : ($unreachable ? app(NodeSideResidue::class)->describe(array_map(RoleName::from(...), array_values($roles)), true) : []),
                'follow_up' => $unreachable && ! $mac ? app(NodeSideResidue::class)->followUp(true) : null];
            $this->store->change($operation, ['input' => [...$operation->input, '_node_plan' => $steps, '_node_result' => $result, '_node_failure_result' => ['id' => $node->id, 'name' => $node->name, 'removed' => false]],
                'state' => [...($operation->state ?? []), 'child_intents' => $intents, 'removal_intent' => [
                    'id' => $node->id, 'name' => $node->name, 'prior_status' => $node->status->value, 'offline' => $unreachable,
                    'public_key' => $node->wireguard_public_key, 'route_ids' => array_column($routeRows, 'id'), 'route_rows' => $routeRows,
                    'role_ids' => $node->roles()->orderBy('id')->pluck('id')->all(),
                    'roles' => $roles]]]);

            return $operation;
        });
    }
}
