<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Validates single-purpose transfer compensation and projects its proven receipts. Swamp executes it.
 *
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type RunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class TransferCompensation implements OperationChildAdmission, OperationFamilyChildren, OperationFamilyExecution, OperationGatewayCommand
{
    public function __construct(private StartOperationAction $operations) {}

    public function eligible(Operation $root): bool
    {
        if ($root->kind !== 'instance.transfer' || ! app(InstanceLifecycleChildren::class)->succeeded($root, 'transfer-stop-source')) {
            return false;
        }
        $transfer = app(InstanceTransferState::class)->transfer($root);
        $runs = ValidatedData::object($root->state['executor_runs'] ?? []);
        $capture = $runs['transfer-capture'] ?? null;
        if ($transfer->cutover_at !== null || is_array($capture) && ($capture['started'] ?? false) === true && ($capture['result'] ?? null) === null) {
            return false;
        }
        $destination = $root->children()->where('child_key', 'transfer-destination')->first();
        if ($destination !== null) {
            app(OperationChildren::class)->verify($root, $destination);
            if (! $destination->status->terminal()) {
                return false;
            }
        }

        $result = is_array($capture) && is_array($capture['result'] ?? null) ? $capture['result'] : [];

        return $root->cancel_requested_at !== null
            || $this->confirmed($result['error_code'] ?? null)
            || $destination?->status === OperationStatus::Failed && $this->confirmed($destination->error_code);
    }

    private function confirmed(mixed $code): bool
    {
        return is_string($code) && ! in_array($code, ['operation.cancelled', 'instance.lifecycle_busy', 'instance.source_busy',
            'operation.execution_uncertain', 'operation.execution_busy', 'operation.execution_failed', 'operation.outcome_unknown',
            'operation.receipt_unavailable', 'operation.child_unproven', 'operation.lease_lost'], true);
    }

    public function admit(Operation $parent, array $intent): Operation
    {
        $root = $parent->kind === 'instance.transfer' ? $parent : $this->root($parent);
        $transfer = app(InstanceTransferState::class)->transfer($root);
        $expected = $parent->kind === 'instance.transfer'
            ? ['transfer-rollback', 'instance.transfer_rollback', $transfer->source_node_id]
            : ['transfer-discard-destination', 'instance.transfer_discard', $transfer->destination_node_id];
        if (! $this->eligible($root) || [$intent['key'], $intent['kind'], $intent['node_id']] !== $expected
            || $intent['input'] !== ['instance_id' => $transfer->instance_id, 'transfer_id' => $transfer->id]) {
            throw $this->forbidden();
        }
        if ($parent->kind !== 'instance.transfer') {
            $this->proven($parent, 'rollback-record');
        }

        return $this->operations->execute($intent['kind'], 'instance', ValidatedData::integer($transfer->instance_id), $intent['node_id'],
            $intent['input'], $parent->kind, $parent->request_id);
    }

    public function root(Operation $operation): Operation
    {
        $parent = $operation->parent_operation_id === null ? null : Operation::query()->find($operation->parent_operation_id);
        $expected = match ($operation->kind) {
            'instance.transfer_rollback' => ['instance.transfer', 'transfer-rollback'],
            'instance.transfer_discard' => ['instance.transfer_rollback', 'transfer-discard-destination'],
            default => throw $this->forbidden(),
        };
        if ($parent === null || [$parent->kind, $operation->child_key] !== $expected) {
            throw $this->forbidden();
        }
        app(OperationChildren::class)->verify($parent, $operation);
        $root = $parent->kind === 'instance.transfer' ? $parent : $this->root($parent);
        $transfer = app(InstanceTransferState::class)->transfer($root);
        $instance = Instance::query()->findOrFail($transfer->instance_id);
        $node = $operation->kind === 'instance.transfer_discard' ? $transfer->destination_node_id : $transfer->source_node_id;
        if ($operation->target_type !== 'instance' || $operation->target_id !== $transfer->instance_id || $operation->node_id !== $node
            || $operation->input !== ['instance_id' => $transfer->instance_id, 'transfer_id' => $transfer->id]
            || $transfer->cutover_at !== null || $instance->node_id !== $transfer->source_node_id || $instance->checkout_path !== $transfer->source_path) {
            throw $this->forbidden();
        }

        return $root;
    }

    public function context(Operation $operation): array
    {
        $root = $this->root($operation);
        $transfer = app(InstanceTransferState::class)->transfer($root);
        $commands = $operation->kind === 'instance.transfer_rollback'
            ? [['rollback-record', 'gateway', 'instance.transfer-compensation', ['action' => 'record']],
                ['rollback-state', 'gateway', 'instance.transfer-compensation', ['action' => 'restore']]]
            : [['transfer-discard-checkout', 'instance_lifecycle', 'instance.transfer-source', ['operation' => 'discard', 'destination' => $transfer->destination_path]]];
        $commands[] = ['compensation-proof', 'gateway', 'instance.transfer-compensation', ['action' => 'prove']];
        $descriptors = [];
        foreach ($commands as [$key, $transport, $name, $input]) {
            $descriptors[] = ['key' => $key, 'step' => $key, 'transport' => $transport, 'name' => $name, 'input' => $input, 'resumable' => true, 'required' => true];
        }

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ([$descriptor['key'], $descriptor['transport'], $descriptor['name']] === [$run['key'], $run['transport'], $run['name']]) {
                return $descriptor['input'];
            }
        }
        throw $this->forbidden();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->input($operation, $run);
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key']) {
                break;
            }
            $this->proven($operation, $descriptor['key']);
        }
        if ($operation->kind === 'instance.transfer_discard') {
            $parent = Operation::query()->findOrFail($operation->parent_operation_id);
            $this->proven($parent, 'rollback-record');
        } elseif (in_array($run['key'], ['rollback-state', 'compensation-proof'], true)) {
            $this->childrenProven($operation);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $run['key'] === 'transfer-discard-checkout' && ! isset($receipt['error_code']) ? ['discarded' => true] : $receipt;
    }

    public function childContext(Operation $parent): array
    {
        $transfer = app(InstanceTransferState::class)->transfer($this->root($parent));
        $children = [];
        if ($parent->kind === 'instance.transfer_rollback' && $this->ready($parent, 'rollback-record')) {
            $abandon = $parent->state['lifecycle_sqlite_abandon'] ?? null;
            if (is_array($abandon)) {
                $children[] = ['key' => 'transfer-abandon-sqlite', 'kind' => 'database.sqlite_seed_abandon',
                    'node_id' => ValidatedData::integer($abandon['node_id']), 'input' => ValidatedData::object($abandon['input'])];
            }
            if ($abandon === null || app(InstanceLifecycleChildren::class)->succeeded($parent, 'transfer-abandon-sqlite')) {
                $children[] = ['key' => 'transfer-discard-destination', 'kind' => 'instance.transfer_discard', 'node_id' => $transfer->destination_node_id,
                    'input' => ['instance_id' => $transfer->instance_id, 'transfer_id' => $transfer->id]];
            }
            if (app(InstanceLifecycleChildren::class)->succeeded($parent, 'transfer-discard-destination')) {
                $children[] = ['key' => 'transfer-restore-source', 'kind' => 'process.instance_runtime', 'node_id' => $transfer->source_node_id,
                    'input' => ['instance_id' => $transfer->instance_id, 'action' => 'transfer_restore']];
            }
        }

        return ['intent' => ['node_ids' => [$transfer->source_node_id, $transfer->destination_node_id]], 'children' => $children];
    }

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'], 'properties' => ['action' => ['enum' => ['record', 'restore', 'prove']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $root = $this->root($operation);
        $transfer = app(InstanceTransferState::class)->transfer($root);
        if ($input['action'] === 'record' && $operation->kind === 'instance.transfer_rollback') {
            DB::transaction(function () use ($root, $operation, $transfer): void {
                if (($operation->state['rollback_recorded'] ?? false) === true) {
                    return;
                }
                $abandon = null;
                if (isset($root->state['lifecycle_sqlite_seed'])) {
                    $seed = app(InstanceSqliteSeedIntent::class)->transferChild($root);
                    $abandon = app(PrepareDatabaseChildAction::class)->sqliteSeedAbandon(Instance::query()->findOrFail($transfer->instance_id), ValidatedData::integer($seed['input']['intent_id']), $operation);
                }
                $operation->update(['state' => [...($operation->state ?? []), 'rollback_recorded' => true, 'lifecycle_sqlite_abandon' => $abandon]]);
                $transfer->update(['status' => InstanceTransferStatus::Failed, 'recovery_evidence' => [...($transfer->recovery_evidence ?? []), 'rollback_pending' => true]]);
            });

            return ['recorded' => true, 'seed_abandon_required' => is_array($operation->state['lifecycle_sqlite_abandon'] ?? null)];
        }
        if ($input['action'] === 'restore' && $operation->kind === 'instance.transfer_rollback') {
            $this->childrenProven($operation);

            return app(InstanceTransferState::class)->transition($operation, ['action' => 'transfer-rollback-state']);
        }
        if ($input['action'] !== 'prove') {
            throw $this->forbidden();
        }
        $this->beforeRun($operation, ['worker' => '', 'key' => 'compensation-proof', 'transport' => 'gateway', 'name' => 'instance.transfer-compensation', 'input' => ['action' => 'prove']]);

        return ['lifecycle_complete' => true, 'transfer_id' => $transfer->id, 'restored' => $operation->kind === 'instance.transfer_rollback'];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            foreach ($this->context($operation)['descriptors'] as $descriptor) {
                $this->proven($operation, $descriptor['key']);
            }
            if ($operation->kind === 'instance.transfer_rollback') {
                $this->childrenProven($operation);
            }

            return;
        }
        if ($outcome->status === OperationStatus::Cancelled && $operation->cancel_requested_at !== null) {
            return;
        }
        foreach (ValidatedData::object($operation->state['executor_runs'] ?? []) as $entry) {
            if ($outcome->errorCode !== null && is_array($entry) && is_array($entry['result'] ?? null)
                && ($entry['result']['error_code'] ?? null) === $outcome->errorCode) {
                return;
            }
        }
        foreach ($operation->children()->get() as $child) {
            app(OperationChildren::class)->verify($operation, $child);
            if ($outcome->errorCode !== null && $child->status->terminal() && $child->error_code === $outcome->errorCode) {
                return;
            }
        }
        throw $this->forbidden();
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function childrenProven(Operation $operation): void
    {
        if (is_array($operation->state['lifecycle_sqlite_abandon'] ?? null)) {
            app(InstanceLifecycleExecution::class)->assertChild($operation, 'transfer-abandon-sqlite', 'database.sqlite_seed_abandon');
        }
        app(InstanceLifecycleExecution::class)->assertChild($operation, 'transfer-discard-destination', 'instance.transfer_discard');
        app(InstanceLifecycleExecution::class)->assertChild($operation, 'transfer-restore-source', 'process.instance_runtime');
    }

    private function ready(Operation $operation, string $key): bool
    {
        try {
            $this->proven($operation, $key);

            return true;
        } catch (ResourceOperationException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function proven(Operation $operation, string $key): array
    {
        $descriptor = array_find($this->context($operation)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $key);
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $receipt = $runs[$key] ?? null;
        if ($descriptor === null || ! is_array($receipt) || ! is_array($receipt['result'] ?? null) || isset($receipt['result']['error_code'])
            || ($receipt['transport'] ?? null) !== $descriptor['transport'] || ($receipt['name'] ?? null) !== $descriptor['name']
            || ($receipt['input'] ?? null) !== $descriptor['input'] || ! $operation->steps()->where('name', $key)->where('status', 'succeeded')->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'The transfer compensation step has no successful keyed proof.', 409);
        }

        return ValidatedData::object($receipt['result']);
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden', 'The compensation does not belong to this stopped, uncut transfer.', 422);
    }
}
