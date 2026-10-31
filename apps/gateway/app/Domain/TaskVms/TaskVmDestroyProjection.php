<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalPublicInput;
use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project native completion only. No provider read, SSH, child or completion contract. */
final readonly class TaskVmDestroyProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private TaskVmDestroyIntent $intent, private NodeRemovalRecords $nodes, private NodeRemovalPublicInput $publicNodes) {}

    public function input(Operation $operation): array
    {
        return $this->view($operation, $operation->node_id);
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        $this->intent->owned($operation);
        if ($callerNodeId === ($operation->input['gateway_node_id'] ?? null) || $callerNodeId === ($operation->input['issuer_node_id'] ?? null)) {
            return;
        }
        if ($operation->input['node_removal'] === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This VM has no admitted Node cleanup inventory.', 403);
        }
        $snapshot = ValidatedData::object($operation->input['node_removal']);
        $participants = $snapshot['participants'];
        if (! is_array($participants) || ! array_any($participants, static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $callerNodeId)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is outside the admitted VM cleanup inventory.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return $this->view($operation, $callerNodeId);
    }

    /** @return array<string,mixed> */
    private function view(Operation $operation, ?int $callerNodeId): array
    {
        $input = $this->intent->input($operation);
        $input['_wireguard'] = NodeWireGuardInput::bound($operation);
        $snapshot = $input['node_removal'] === null ? null : ValidatedData::object($input['node_removal']);
        $participants = $snapshot['participants'] ?? [];
        $member = is_array($participants) && array_any($participants, static fn (mixed $row): bool => is_array($row) && ($row['node_id'] ?? null) === $callerNodeId);
        $input['node_cleanup_participant'] = $snapshot !== null && $member
            ? [...$this->publicNodes->participant($snapshot, Node::query()->findOrFail($callerNodeId),
                restoring: ($operation->state['node_cleanup_restoring'] ?? false) === true),
                'proxy_node_ids' => $input['proxy_node_ids']] : null;

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result);
            }
            $vm = $this->intent->owned($operation, lock: true);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($status === OperationStatus::Running && ($result['node_cleanup_restoring'] ?? false) === true && $vm->node_id !== null) {
                $snapshot = ValidatedData::object($operation->input['node_removal']);
                $this->nodes->restore($snapshot);
                $projected->update(['state' => [...($projected->state ?? []), 'node_cleanup_restoring' => true]]);
                if ($operation->input['_wireguard'] !== null) {
                    NodeWireGuardInput::beginRestoration($projected);
                }
            } elseif ($status === OperationStatus::Running && ($result['node_cleanup_started'] ?? false) === true && $vm->node_id !== null) {
                $this->nodes->begin(ValidatedData::object($operation->input['node_removal']));
                $vm->update(['state' => TaskVmState::Destroying]);
            }
            if ($status === OperationStatus::Succeeded) {
                $this->projectRemoval($operation->input, $result);
            } elseif ($status === OperationStatus::Failed) {
                $vm->update(['error_code' => ValidatedData::string($data['error_code']), 'error_message' => 'The task VM destruction did not complete.']);
            }

            return $projected;
        });
    }

    /** Call inside the owning parent's projection transaction after native success.
     * @param  array<string,mixed>  $canonicalInput
     * @param  array<string,mixed>  $result
     */
    public function projectRemoval(array $canonicalInput, array $result): TaskVm
    {
        $this->intent->available($canonicalInput);
        $vm = $this->intent->ownedInput($canonicalInput, lock: true);
        if ($canonicalInput['node_removal'] !== null) {
            $this->nodes->remove(ValidatedData::object($canonicalInput['node_removal']), $result);
            $vm->refresh();
        }
        $vm->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'pi_token' => null, 'model_key' => null, 'provider_bootstrap' => null,
            'worker_stop_required' => false, 'error_code' => null, 'error_message' => null, ...$vm::ForgetTopology]);

        return $vm;
    }
}
