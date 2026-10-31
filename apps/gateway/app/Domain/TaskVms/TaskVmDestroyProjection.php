<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalPublicInput;
use App\Domain\Nodes\NodeRemovalRecords;
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
        return $this->intent->input($operation);
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
        if ($callerNodeId === ($operation->input['gateway_node_id'] ?? null) || $callerNodeId === ($operation->input['issuer_node_id'] ?? null)) {
            return $this->intent->input($operation);
        }

        return $this->publicNodes->participant(ValidatedData::object($operation->input['node_removal']), Node::query()->findOrFail($callerNodeId));
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
            if ($status === OperationStatus::Succeeded) {
                $this->projectRemoval($operation->input);
            } elseif ($status === OperationStatus::Failed) {
                $vm->update(['error_code' => ValidatedData::string($data['error_code']), 'error_message' => 'The task VM destruction did not complete.']);
            }

            return $projected;
        });
    }

    /** Call inside the owning parent's projection transaction after native success.
     * @param  array<string,mixed>  $canonicalInput
     */
    public function projectRemoval(array $canonicalInput): TaskVm
    {
        $this->intent->available($canonicalInput);
        $vm = $this->intent->ownedInput($canonicalInput, lock: true);
        if ($canonicalInput['node_removal'] !== null) {
            $this->nodes->remove(ValidatedData::object($canonicalInput['node_removal']));
            $vm->refresh();
        }
        $vm->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'pi_token' => null, 'model_key' => null, 'provider_bootstrap' => null,
            'worker_stop_required' => false, 'error_code' => null, 'error_message' => null, ...$vm::ForgetTopology]);

        return $vm;
    }
}
