<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;

/** Finalizes stored intent only after all serving-Node cleanup and network children are proven. */
final readonly class NodeRemovalOperationCompletion implements OperationCompletion
{
    public function __construct(private NodeLifecycleOperationExecution $execution, private NodeRemovalPolicy $policy,
        private RecordEventBroadcaster $broadcaster, private WebSocketRegistry $registry) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->execution->assertCompletion($operation, $outcome);
        if ($operation->kind !== 'node.remove') {
            return;
        }
        $node = Node::query()->lockForUpdate()->findOrFail($operation->node_id);
        if ($outcome->status !== OperationStatus::Succeeded) {
            if ($outcome->errorCode === 'node.removal_rollback_failed') {
                $node->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'removal:restore', 'error_code' => $outcome->errorCode]);
            }

            return;
        }
        $intent = ValidatedData::object($operation->state['removal_intent'] ?? []);
        $caller = Node::query()->findOrFail(ValidatedData::integer($operation->input['caller_node_id'] ?? null));
        $this->policy->protected($node, $caller);
        $offline = ($intent['offline'] ?? false) === true;
        $this->policy->resources($node, $offline);
        if ($node->status !== LifecycleStatus::Removing || $node->roles()->exists() || ($intent['id'] ?? null) !== $node->id
            || ($intent['public_key'] ?? null) !== $node->wireguard_public_key) {
            throw new ResourceOperationException('node.removal_intent_changed', 'The Node changed after its removal intent was recorded.', 409);
        }
        $rows = $intent['route_rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! OperationChildIntent::same($rows, $this->policy->routeRows($node))) {
            throw new ResourceOperationException('operation.completion_unproven', 'The preserved Route evidence is missing.', 409);
        }
        foreach ($rows as $snapshot) {
            $snapshot = ValidatedData::object($snapshot);
            $route = Route::query()->lockForUpdate()->find(ValidatedData::integer($snapshot['id'] ?? null));
            if ($route === null || ! OperationChildIntent::same($snapshot, $route->attributesToArray()) || $route->status->isAuthoritative()) {
                throw new ResourceOperationException('node.removal_intent_changed', 'A preserved Route changed while its serving Nodes were cleaned.', 409);
            }
            if ($route->node_id === $node->id || $route->generation_basis_node_id === $node->id) {
                $route->delete();
            } elseif ($route->transition_node_id === $node->id) {
                $route->update(['transition_node_id' => null]);
            }
        }
        if ($offline) {
            $this->registry->abandonNode($node->id);
            Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->get()->each(static fn (Process $process): bool => $process->delete() ?? false);
        }
        TaskVm::query()->where('host_node_id', $node->id)->delete();
        TaskVmImageBuild::query()->where('host_node_id', $node->id)->whereIn('state', [TaskVmImageBuildState::Succeeded, TaskVmImageBuildState::Failed])->delete();
        WebSocketRuntime::query()->where('node_id', $node->id)->where('required', false)->delete();
        $node->delete();
        $this->broadcaster->broadcast(RecordEventType::NodeDeleted, $node->id, ['id' => $node->id, 'name' => $node->name]);
    }
}
