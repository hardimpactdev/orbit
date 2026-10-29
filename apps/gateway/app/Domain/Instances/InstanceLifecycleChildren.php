<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Derives eligible child intents from admitted placement and proven receipts. It never admits or executes children.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceLifecycleChildren
{
    /** @return array{intent:array<string,mixed>,children:list<ChildIntent>} */
    public function context(Operation $parent): array
    {
        $id = ValidatedData::integer($parent->input['instance_id']);
        if ($parent->target_type !== 'instance' || $parent->target_id !== $id || $parent->node_id === null) {
            throw new ResourceOperationException('operation.child_forbidden', 'Lifecycle children require their recorded Instance and Node.', 422);
        }
        $children = match ($parent->kind) {
            'instance.rename' => $this->rename($parent),
            default => [],
        };
        $indexed = [];
        foreach ($children as $intent) {
            $indexed[$intent['key']] = $intent;
        }
        // Earlier intents survive DB projections and row deletion. Their stamped snapshots remain the authority.
        foreach ($parent->children()->get() as $child) {
            app(OperationChildren::class)->verify($parent, $child);
            $intent = ValidatedData::object($child->child_admission['intent'] ?? []);
            $key = ValidatedData::string($intent['key']);
            $indexed[$key] = ['key' => $key, 'kind' => ValidatedData::string($intent['kind']),
                'node_id' => $intent['node_id'] === null ? null : ValidatedData::integer($intent['node_id']),
                'input' => ValidatedData::object($intent['input'])];
        }
        $nodes = [$parent->node_id];
        foreach ($indexed as $intent) {
            if ($intent['node_id'] !== null) {
                $nodes[] = $intent['node_id'];
            }
        }

        return ['intent' => ['node_ids' => array_values(array_unique($nodes))], 'children' => array_values($indexed)];
    }

    /** @return list<ChildIntent> */
    private function rename(Operation $parent): array
    {
        return $parent->input['domain'] !== null && $this->ready($parent, 'rename-inspect')
            ? [$this->child($parent, 'route-update', 'route.update', ['route_id' => $parent->input['route_id'], 'domain' => $parent->input['domain']])]
            : [];
    }

    public function succeeded(Operation $parent, string $key): bool
    {
        if (! $parent->children()->where('child_key', $key)->exists()) {
            return false;
        }
        $child = app(OperationChildren::class)->child($parent, $key);

        return $child->status === OperationStatus::Succeeded;
    }

    public function ready(Operation $parent, string ...$keys): bool
    {
        foreach ($keys as $key) {
            try {
                app(InstanceLifecycleExecution::class)->proven($parent, $key);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'operation.completion_unproven') {
                    throw $exception;
                }

                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed>|null */
    public function stepOutcome(Operation $parent, string $key): ?array
    {
        if (! $parent->children()->where('child_key', $key)->exists()) {
            return null;
        }
        $child = app(OperationChildren::class)->child($parent, $key);
        if (! $child->status->terminal()) {
            return null;
        }

        return [...($child->result ?? []), 'error_code' => $child->error_code ?? $child->result['error_code'] ?? null];
    }

    public function rollbackSetup(Operation $parent): bool
    {
        return in_array($this->stepOutcome($parent, 'setup')['outcome'] ?? null, ['missing', 'failed', 'deadline'], true);
    }

    public function canRemoveFailedCreate(Operation $parent): bool
    {
        if ($parent->kind !== 'instance.create' || $parent->input['initial_status'] === 'active') {
            return false;
        }
        foreach (['source-prepare', 'source-inspect', 'source-resolve', 'source-profile', 'source-configuration'] as $key) {
            $result = app(InstanceLifecycleExecution::class)->recordedResult($parent, $key);
            if ($this->confirmedRefusal($result)) {
                return true;
            }
        }
        foreach (['environment-sync', 'vp-environment', 'route-converge', 'database', 'database-environment-sync'] as $key) {
            $outcome = $this->stepOutcome($parent, $key);
            if ($outcome !== null && $this->confirmedRefusal($outcome)) {
                return true;
            }
        }
        $teardown = $this->stepOutcome($parent, 'rollback-teardown');

        return $this->rollbackSetup($parent) && in_array($teardown['outcome'] ?? null, ['succeeded', 'missing', 'failed', 'deadline'], true);
    }

    /** @param array<string,mixed> $result */
    public function confirmedRefusal(array $result): bool
    {
        $code = $result['error_code'] ?? null;
        if (isset($result['outcome']) && ! in_array($result['outcome'], ['failed', 'missing'], true)) {
            return false;
        }

        return is_string($code) && ! in_array($code, ['operation.cancelled', 'instance.lifecycle_busy', 'instance.source_busy',
            'operation.execution_uncertain', 'operation.execution_busy', 'operation.execution_failed', 'operation.outcome_unknown',
            'operation.receipt_unavailable', 'operation.child_unproven', 'operation.lease_lost'], true);
    }

    /** @param array<string,mixed> $input
     * @return ChildIntent
     */
    private function child(Operation $parent, string $key, string $kind, array $input, ?int $node = null): array
    {
        return ['key' => $key, 'kind' => $kind, 'node_id' => $node ?? $parent->node_id, 'input' => $input];
    }
}
