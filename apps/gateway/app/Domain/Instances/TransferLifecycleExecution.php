<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Binds transfer source effects to immutable placement and retains safe capture facts for the binary relay.
 *
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type RunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class TransferLifecycleExecution
{
    /** @return array<string, mixed> */
    public function intent(Operation $operation): array
    {
        $transfer = app(InstanceTransferState::class)->transfer($operation);
        if ($operation->kind === 'instance.transfer') {
            return $operation->input;
        }
        $parent = $operation->parent_operation_id === null ? null : Operation::query()->find($operation->parent_operation_id);
        if ($parent === null || $parent->kind !== 'instance.transfer' || $parent->target_id !== $transfer->instance_id
            || ($parent->input['transfer_id'] ?? null) !== $transfer->id || $operation->node_id !== $transfer->destination_node_id) {
            throw $this->forbidden();
        }
        app(OperationChildren::class)->verify($parent, $operation);
        $action = match ([$operation->kind, $operation->child_key]) {
            ['instance.transfer_destination', 'transfer-destination'] => 'materialize',
            ['instance.transfer_destination', 'transfer-discard-destination'] => 'discard',
            ['instance.transfer_activate', 'transfer-activate-destination'] => 'activate',
            default => throw $this->forbidden(),
        };

        return [...$parent->input, 'instance_id' => $transfer->instance_id, 'transfer_id' => $transfer->id,
            'node_id' => $operation->node_id, 'checkout' => $transfer->destination_path,
            'name' => $transfer->destination_name, 'domain' => $transfer->destination_domain,
            'route_id' => $transfer->destination_route_id, 'transfer_action' => $action];
    }

    public function capturePending(Operation $operation): bool
    {
        $capture = ValidatedData::object($operation->state['executor_runs'] ?? [])['transfer-capture'] ?? null;

        return is_array($capture) && ($capture['started'] ?? false) === true && ($capture['result'] ?? null) === null;
    }

    /** @return list<Descriptor> */
    public function descriptors(Operation $operation): array
    {
        $transfer = app(InstanceTransferState::class)->transfer($operation);
        if ($operation->kind !== 'instance.transfer') {
            throw $this->forbidden();
        }
        if ($operation->input['initial_completed'] === true) {
            if ($transfer->completed_at === null) {
                throw $this->forbidden();
            }

            return [];
        }
        $execution = app(InstanceLifecycleExecution::class);
        $gateway = static fn (string $key, bool $required = true): array => $execution->descriptor(
            $key, 'gateway', 'instance.lifecycle-state', ['action' => $key, 'operation_id' => $operation->id], $required,
        );

        return [
            ...($operation->input['initial_cutover'] === true ? [] : [
                $execution->descriptor('transfer-capture', 'instance_lifecycle', 'instance.transfer-source', [
                    'operation' => 'capture', 'source' => $transfer->source_path,
                    'layout' => $transfer->source_layout->value, 'sqlite_source' => $transfer->sqlite_source_path,
                ], resumable: false),
                $gateway('transfer-sqlite-intent'),
                $gateway('transfer-cutover'),
            ]),
            $gateway('transfer-destination-activated'),
            $execution->descriptor('transfer-cleanup-source', 'instance_lifecycle', 'instance.transfer-source', [
                'operation' => 'cleanup', 'source' => $transfer->source_path, 'common' => $transfer->common_repository_path,
            ]),
            $gateway('transfer-finish'),
        ];
    }

    /** @param RunInput $run */
    public function beforeRun(Operation $operation, array $run): void
    {
        $transfer = app(InstanceTransferState::class)->transfer($operation);
        $execution = app(InstanceLifecycleExecution::class);
        if ($operation->cancel_requested_at !== null && $transfer->cutover_at === null) {
            throw new ResourceOperationException('operation.cancelled', 'Only admitted compensation children may act after transfer cancellation.', 409);
        }
        if ($run['key'] === 'transfer-capture') {
            if ($transfer->cutover_at !== null) {
                throw $this->forbidden();
            }
            $execution->assertChild($operation, 'transfer-stop-source', 'process.instance_runtime');
        }
        if (in_array($run['key'], ['transfer-sqlite-intent', 'transfer-cutover'], true)) {
            $execution->proven($operation, 'transfer-capture');
        }
        if ($run['key'] === 'transfer-cutover') {
            $execution->proven($operation, 'transfer-sqlite-intent');
        }
        if ($run['key'] === 'transfer-cleanup-source') {
            if ($transfer->cutover_at === null || $transfer->current_step !== InstanceTransferStep::DestinationActivated) {
                throw $this->forbidden();
            }
            $execution->assertChild($operation, 'transfer-retire-source', 'route.retire_source');
            $execution->assertChild($operation, 'transfer-retire-runtime', 'process.instance_runtime');
        }
    }

    /**
     * @param  RunInput  $run
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['transport'] !== 'instance_lifecycle' || isset($receipt['error_code'])) {
            return $receipt;
        }
        $transfer = app(InstanceTransferState::class)->transfer($operation);
        if ($run['key'] === 'transfer-cleanup-source') {
            return ['cleaned' => true];
        }
        if ($run['key'] !== 'transfer-capture') {
            return $receipt;
        }
        $head = $receipt['head'] ?? null;
        $branch = $receipt['branch'] ?? null;
        $detached = $receipt['detached'] ?? null;
        $archive = $receipt['archive'] ?? null;
        $common = $receipt['common'] ?? null;
        $refs = $receipt['refs'] ?? null;
        $prefix = '/tmp/orbit-transfer-'.basename($transfer->source_path).'-';
        if (! is_string($head) || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $head) !== 1
            || ! is_bool($detached) || ($detached ? $branch !== null : ! is_string($branch) || ! GitBranchName::isValid($branch))
            || ! is_string($archive) || ! str_starts_with($archive, $prefix)
            || preg_match('/\A[0-9a-f]{16}\.tar\z/D', substr($archive, strlen($prefix))) !== 1
            || ($common !== null && ! is_string($common))
            || ! is_array($refs) || ! array_is_list($refs) || ! array_all($refs, static fn (mixed $ref): bool => is_string($ref))) {
            throw new ResourceOperationException('instance.transfer_failed', 'Transfer capture returned invalid source evidence.', 409);
        }
        app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'lifecycle_transfer_capture' => [
            'head' => $head, 'branch' => $branch, 'detached' => $detached,
            'archive' => $archive, 'common' => $common, 'refs' => ValidatedData::stringList($refs),
        ]]]);
        if ($transfer->current_step === InstanceTransferStep::Reserved) {
            $transfer->update(['current_step' => InstanceTransferStep::SourceCaptured, 'status' => InstanceTransferStatus::InProgress,
                'common_repository_path' => $common ?? $transfer->common_repository_path, 'failed_step' => null, 'error_code' => null]);
        }

        return ['captured' => true, 'commit' => $head, 'branch' => $branch];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The transfer effect does not match its recorded placement.', 422);
    }
}
