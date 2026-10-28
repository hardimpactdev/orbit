<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The integration's admitted effects and required receipts. No Node work runs here.
 *
 * @phpstan-import-type Flow from IntegrationIntent
 */
final readonly class IntegrationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        return ['descriptors' => array_values(IntegrationIntent::effects($operation))];
    }

    /** @return array{intent: array<string, mixed>, children: list<array{key: string, kind: string, node_id: int, input: array<string, mixed>}>} */
    public function childContext(Operation $operation): array
    {
        $intents = [];
        foreach (IntegrationIntent::flow($operation) as $entry) {
            try {
                if (isset($entry['when'])) {
                    $condition = $entry['when'];
                    $evidence = ValidatedData::object($operation->state['steps'] ?? [])[$condition['step']] ?? null;
                    if (! is_array($evidence) || ! array_key_exists($condition['field'], $evidence)) {
                        break;
                    }
                    if ($evidence[$condition['field']] !== $condition['equals']) {
                        continue;
                    }
                }
                if ($entry['type'] === 'child') {
                    $intents[] = ['key' => $entry['key'], 'kind' => $entry['kind'], 'node_id' => $entry['node_id'], 'input' => $entry['input']];
                }
                $this->assertEntry($operation, $entry);
            } catch (ResourceOperationException) {
                break;
            }
        }
        $recovery = IntegrationIntent::data($operation)['failure_recovery'] ?? null;
        if (is_array($recovery)) {
            $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
            $restoration = $runs['collector-takeover-restore'] ?? null;
            if (is_array($restoration) && is_array($restoration['result'] ?? null) && ($restoration['result']['restored'] ?? false) === true) {
                $child = ValidatedData::object($recovery['child']);
                $intents[] = ['key' => ValidatedData::string($child['key']), 'kind' => ValidatedData::string($child['kind']),
                    'node_id' => ValidatedData::integer($child['node_id']), 'input' => ValidatedData::object($child['input'])];
            }
        }
        if ($this->recoveryAllowed($operation)) {
            foreach (IntegrationIntent::flow($operation, 'failure_flow') as $entry) {
                if ($entry['type'] === 'child') {
                    $intents[] = ['key' => $entry['key'], 'kind' => $entry['kind'], 'node_id' => $entry['node_id'], 'input' => $entry['input']];
                }
                try {
                    $this->assertEntry($operation, $entry);
                } catch (ResourceOperationException) {
                    break;
                }
            }
        }

        return ['intent' => IntegrationIntent::data($operation), 'children' => $intents];
    }

    public function input(Operation $operation, array $run): array
    {
        $descriptor = IntegrationIntent::effects($operation)[$run['key']] ?? null;
        if (! is_array($descriptor) || $descriptor['transport'] !== $run['transport'] || $descriptor['name'] !== $run['name']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This effect is outside the integration intent.', 422);
        }

        return $descriptor['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $flow = IntegrationIntent::flow($operation);
        if ($this->recoveryAllowed($operation) && ! in_array($run['key'], array_column($flow, 'key'), true)) {
            $flow = IntegrationIntent::flow($operation, 'failure_flow');
        }
        foreach ($flow as $entry) {
            if ($entry['type'] === 'run' && $entry['key'] === $run['key']) {
                return;
            }
            $this->assertEntry($operation, $entry);
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This effect is not in the integration sequence.', 422);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Failed && $this->recoveryAllowed($operation)) {
            $recovery = IntegrationIntent::flow($operation, 'failure_flow');
            if ($outcome->errorCode === 'websocket.rollback_failed') {
                if (! $this->hasProvenFailure($operation, $recovery)) {
                    throw new ResourceOperationException('operation.completion_unproven', 'The rollback failure has no recorded refusal.', 409);
                }
            } else {
                foreach ($recovery as $entry) {
                    $this->assertEntry($operation, $entry);
                }
            }
        }
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        foreach (IntegrationIntent::flow($operation) as $entry) {
            $this->assertEntry($operation, $entry);
        }
    }

    public function recoveryAllowed(Operation $operation): bool
    {
        $condition = IntegrationIntent::data($operation)['failure_when'] ?? null;
        if (! is_array($condition) || ! isset(IntegrationIntent::data($operation)['failure_flow'])) {
            return false;
        }
        $step = ValidatedData::string($condition['step']);
        $result = ValidatedData::object($operation->state['steps'] ?? [])[$step] ?? null;
        if (! is_array($result) || ($result[ValidatedData::string($condition['field'])] ?? null) !== $condition['equals']) {
            return false;
        }
        try {
            $this->assertEntry($operation, ['type' => 'run', 'key' => $step]);
        } catch (ResourceOperationException) {
            return false;
        }

        return $this->hasProvenFailure($operation, IntegrationIntent::flow($operation));
    }

    /** @param list<Flow> $flow */
    private function hasProvenFailure(Operation $operation, array $flow): bool
    {
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        foreach ($flow as $entry) {
            if ($entry['type'] === 'child') {
                try {
                    $child = app(OperationChildren::class)->child($operation, $entry['key']);
                } catch (ResourceOperationException) {
                    continue;
                }
                if ($child->status === OperationStatus::Failed) {
                    return true;
                }

                continue;
            }
            $descriptor = IntegrationIntent::effects($operation)[$entry['key']];
            $run = $runs[$entry['key']] ?? null;
            $error = is_array($run) && is_array($run['result'] ?? null) ? ($run['result']['error_code'] ?? null) : null;
            if (is_string($error) && ! in_array($error, ['operation.cancelled', 'node.lock_lost', 'operation.lease_lost'], true)
                && $run['name'] === $descriptor['name'] && $run['transport'] === $descriptor['transport']) {
                return true;
            }
        }

        return false;
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @param Flow $entry */
    private function assertEntry(Operation $operation, array $entry): void
    {
        if (isset($entry['when'])) {
            $condition = $entry['when'];
            $evidence = ValidatedData::object($operation->state['steps'] ?? [])[$condition['step']] ?? null;
            if (! is_array($evidence) || ! array_key_exists($condition['field'], $evidence)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The condition has no proven step result.', 409);
            }
            if ($evidence[$condition['field']] !== $condition['equals']) {
                return;
            }
        }
        if ($entry['type'] === 'child') {
            $children = app(OperationChildren::class);
            $child = ($entry['continue_on_failure'] ?? false) ? $children->child($operation, $entry['key']) : $children->requireSucceeded($operation, $entry['key']);
            if ($child->kind !== $entry['kind'] || $child->node_id !== $entry['node_id']
                || (ValidatedData::object($child->child_admission['intent'] ?? [])['input'] ?? null) !== $entry['input'] || (($entry['continue_on_failure'] ?? false) ? ! $child->status->terminal() : $child->status !== OperationStatus::Succeeded)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The integration child has not completed its admitted intent.', 409);
            }

            return;
        }
        $descriptor = IntegrationIntent::effects($operation)[$entry['key']];
        $saved = ValidatedData::object($operation->state['executor_runs'] ?? [])[$entry['key']] ?? null;
        $result = ValidatedData::object($operation->state['steps'] ?? [])[$descriptor['step']] ?? null;
        if (! is_array($saved) || ($saved['transport'] ?? null) !== $descriptor['transport'] || ($saved['name'] ?? null) !== $descriptor['name']
            || ! is_array($saved['result'] ?? null) || isset($saved['result']['error_code'])
            || (match ($descriptor['name']) {
                'http.await-healthy' => ($saved['result']['healthy'] ?? null) !== true,
                'websocket.runtime-active' => ($saved['result']['active'] ?? null) !== true,
                default => false,
            })
            || $saved['result'] !== $result || ! $operation->steps()->where('name', $descriptor['step'])->where('status', OperationStepStatus::Succeeded->value)->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'The integration effect has no successful recorded receipt.', 409);
        }
    }
}
