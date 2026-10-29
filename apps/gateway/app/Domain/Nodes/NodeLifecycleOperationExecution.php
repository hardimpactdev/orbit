<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Sequential Node lifecycle proof. The recorded plan has no worker-selected targets or payloads. */
final readonly class NodeLifecycleOperationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private OperationChildren $children) {}

    public function context(Operation $operation): array
    {
        $descriptors = [];
        foreach ($this->eligible($operation) as $step) {
            if ($step['type'] === 'run') {
                $descriptors[] = $this->descriptor($step);
            }
        }
        $descriptors[] = $this->descriptor(['key' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input' => []]);
        $result = $this->restoreNeeded($operation) ? ['node_restore_required' => true] : [];
        if ($operation->cancel_requested_at !== null && ! $operation->children()->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->exists()
            && (! $this->restoreNeeded($operation) || $operation->children()->where('child_key', 'remove-restore')->exists())) {
            $result = [...$result, ...$this->cancelledResult($operation)];
        }

        return ['descriptors' => $descriptors, 'result' => $result];
    }

    public function childContext(Operation $operation): array
    {
        $intents = [];
        $recorded = $operation->state['child_intents'] ?? [];
        if (! is_array($recorded) || ! array_is_list($recorded)) {
            throw $this->unproven();
        }
        foreach ($this->eligible($operation) as $step) {
            if ($step['type'] === 'child') {
                $intents[] = $this->intent($step);
            }
        }
        if ($this->restoreNeeded($operation)) {
            $intents[] = ['key' => 'remove-restore', 'kind' => 'node.removal_restore', 'node_id' => $operation->node_id, 'input' => []];
        }
        foreach ($intents as $intent) {
            if (! array_any($recorded, static fn (mixed $entry): bool => is_array($entry) && OperationChildIntent::same($entry, $intent))) {
                throw $this->unproven();
            }
        }

        return ['intent' => ['node_ids' => array_values(array_unique(array_column($intents, 'node_id')))], 'children' => $intents];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['transport'] === $run['transport'] && $descriptor['name'] === $run['name']) {
                return $descriptor['input'];
            }
        }
        throw $this->unproven();
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->input($operation, $run);
        if ($run['key'] === 'node-lifecycle-result') {
            $this->result($operation);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (! isset($receipt['error_code']) && in_array($run['name'], ['firewall.rules', 'firewall.router-lan'], true) && ($receipt['matches'] ?? null) !== true) {
            return ['error_code' => 'node.firewall_convergence_failed'];
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        $expected = $operation->cancel_requested_at !== null && $outcome->status !== OperationStatus::Succeeded
            ? $this->cancelledResult($operation) : $this->result($operation);
        if (! OperationChildIntent::same($expected, $outcome->result ?? [])
            || ($outcome->status === OperationStatus::Succeeded && isset($expected['error_code']))) {
            throw $this->unproven();
        }
    }

    public function cancellationBoundary(Operation $operation): null
    {
        return null;
    }

    /** @return array<string,mixed> */
    public function result(Operation $operation): array
    {
        $followUps = [];
        foreach ($this->plan($operation) as $step) {
            $proof = $this->proof($operation, $step);
            if ($proof['status'] === 'pending') {
                throw $this->unproven();
            }
            if ($proof['status'] === 'failed') {
                if (($step['optional'] ?? false) !== true) {
                    if ($this->restoreNeeded($operation) && ! $this->restored($operation)) {
                        return [...$this->failureBase($operation), 'error_code' => 'node.removal_rollback_failed', 'failed_step' => 'remove-restore'];
                    }

                    return [...$this->failureBase($operation), 'error_code' => $proof['error_code'], 'failed_step' => $step['key']];
                }
                $followUps[] = ValidatedData::string($step['key']).':'.$proof['error_code'];
            }
        }
        $result = ['node_plan_proven' => true, ...ValidatedData::object($operation->input['_node_result'] ?? [])];
        if ($followUps !== []) {
            $prior = is_string($result['follow_up'] ?? null) ? [$result['follow_up']] : [];
            $result['follow_up'] = implode(', ', [...$prior, ...$followUps]);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function cancelledResult(Operation $operation): array
    {
        if ($operation->children()->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->exists()) {
            throw $this->unproven();
        }
        $restored = ! $this->restoreNeeded($operation) || $this->restored($operation);

        return [...$this->failureBase($operation), 'error_code' => $restored ? 'operation.cancelled' : 'node.removal_rollback_failed',
            'failed_step' => $restored ? 'operation' : 'remove-restore'];
    }

    /** @return array<string,mixed> */
    private function failureBase(Operation $operation): array
    {
        return ['node_plan_proven' => true, ...ValidatedData::object($operation->input['_node_failure_result'] ?? [])];
    }

    private function restoreNeeded(Operation $operation): bool
    {
        if ($operation->kind !== 'node.remove') {
            return false;
        }
        $begin = $operation->children()->where('child_key', 'remove-begin')->first();
        if ($begin === null || $begin->status !== OperationStatus::Succeeded) {
            return false;
        }
        $this->children->verify($operation, $begin);
        if ($begin->kind !== 'node.removal_begin' || $begin->node_id !== $operation->node_id) {
            throw $this->unproven();
        }
        if ($operation->cancel_requested_at !== null) {
            return true;
        }
        foreach ($this->plan($operation) as $step) {
            if (($step['optional'] ?? false) !== true && $this->proof($operation, $step)['status'] === 'failed') {
                return true;
            }
        }

        return false;
    }

    private function restored(Operation $operation): bool
    {
        $restore = $this->children->child($operation, 'remove-restore');
        if ($restore->kind !== 'node.removal_restore' || $restore->node_id !== $operation->node_id || ! $restore->status->terminal()) {
            throw $this->unproven();
        }

        return $restore->status === OperationStatus::Succeeded;
    }

    /** @return list<array<string,mixed>> */
    private function eligible(Operation $operation): array
    {
        $steps = [];
        foreach ($this->plan($operation) as $step) {
            $steps[] = $step;
            $proof = $this->proof($operation, $step);
            if ($proof['status'] === 'pending' || ($proof['status'] === 'failed' && ($step['optional'] ?? false) !== true)) {
                break;
            }
        }

        return $steps;
    }

    /** @param array<string,mixed> $step
     * @return array{status:'pending'|'failed'|'succeeded',error_code:?string}
     */
    private function proof(Operation $operation, array $step): array
    {
        if ($step['type'] === 'child') {
            $child = $operation->children()->where('child_key', $step['key'])->first();
            if ($child === null) {
                return ['status' => 'pending', 'error_code' => null];
            }
            $this->children->verify($operation, $child);
            if (! OperationChildIntent::same(ValidatedData::object($child->child_admission['intent'] ?? []), $this->intent($step))) {
                throw $this->unproven();
            }
            if (! $child->status->terminal()) {
                return ['status' => 'pending', 'error_code' => null];
            }
            $receipt = $child->result ?? [];
            $error = $child->status === OperationStatus::Succeeded ? null : ($child->error_code ?? 'node.child_failed');
        } else {
            $run = ValidatedData::object($operation->state['executor_runs'] ?? [])[ValidatedData::string($step['key'])] ?? null;
            if (! is_array($run) || ! is_array($run['result'] ?? null)) {
                return ['status' => 'pending', 'error_code' => null];
            }
            if (($run['name'] ?? null) !== $step['name'] || ($run['transport'] ?? null) !== $step['transport']
                || ! OperationChildIntent::same(ValidatedData::object($run['input'] ?? []), ValidatedData::object($step['input']))) {
                throw $this->unproven();
            }
            $receipt = $run['result'];
            $error = isset($receipt['error_code']) ? ValidatedData::string($receipt['error_code']) : null;
        }
        if ($error === null) {
            foreach (ValidatedData::object($step['result'] ?? []) as $field => $expected) {
                if (($receipt[$field] ?? null) !== $expected) {
                    throw $this->unproven();
                }
            }
        }

        return ['status' => $error === null ? 'succeeded' : 'failed', 'error_code' => $error];
    }

    /** @return list<array<string,mixed>> */
    private function plan(Operation $operation): array
    {
        $plan = $operation->input['_node_plan'] ?? null;
        if (! is_array($plan) || ! array_is_list($plan)) {
            throw $this->unproven();
        }

        return array_map(ValidatedData::object(...), $plan);
    }

    /** @param array<string,mixed> $step
     * @return array{key:string,kind:string,node_id:int|null,input:array<string,mixed>}
     */
    private function intent(array $step): array
    {
        return ['key' => ValidatedData::string($step['key']), 'kind' => ValidatedData::string($step['kind']),
            'node_id' => ValidatedData::integer($step['node_id']), 'input' => ValidatedData::object($step['input'])];
    }

    /** @param array<string,mixed> $step
     * @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,required:bool,resumable:bool}
     */
    private function descriptor(array $step): array
    {
        $key = ValidatedData::string($step['key']);
        $transport = ValidatedData::string($step['transport']);

        return ['key' => $key, 'step' => $key, 'transport' => $transport, 'name' => ValidatedData::string($step['name']),
            'input' => ValidatedData::object($step['input']), 'required' => false, 'resumable' => $transport === 'gateway'];
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The Node lifecycle requires its recorded runs and child proofs.', 409);
    }
}
