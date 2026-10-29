<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;

/** Admission and proof on normal Operations. Called under the parent's owned transaction for writes.
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class OperationChildren
{
    public function __construct(private OperationKinds $kinds, private OperationExecutionContract $contract, private OperationIdempotency $idempotency, private OperationRunConstraints $constraints) {}

    /** @return array{intent:array<string,mixed>,children:list<ChildIntent>} */
    private function context(Operation $parent): array
    {
        $execution = $this->contract->execution($parent);
        if (! $execution instanceof OperationFamilyChildren) {
            throw new ResourceOperationException('operation.child_forbidden', 'This family has no admitted child plan.', 422);
        }

        return $execution->childContext($parent);
    }

    /** @param array<string,mixed> $data
     * @return array{operation:Operation,created:bool}
     */
    public function admit(Operation $parent, array $data): array
    {
        $key = ValidatedData::string($data['key']);
        $existing = $parent->children()->where('child_key', $key)->first();
        if ($existing !== null) {
            $this->verify($parent, $existing);
            $this->assertPayload($data, ValidatedData::object($existing->child_admission['intent'] ?? []));

            return ['operation' => $existing, 'created' => false];
        }
        $context = $this->context($parent);
        $matches = array_values(array_filter($context['children'], static fn (array $entry): bool => $entry['key'] === $key));
        if (count($matches) !== 1) {
            throw new ResourceOperationException('operation.child_forbidden', 'The child key is not eligible in the recorded plan.', 422);
        }
        $intent = $matches[0];
        $this->assertAncestors($parent);
        if ($parent->cancel_requested_at !== null && ! $this->compensationAllowed($parent, $intent['kind'])) {
            throw new ResourceOperationException('operation.cancelled', 'The parent was cancelled before child admission.', 409);
        }
        $this->authorize($parent, $intent, $context['intent']);
        $this->assertPayload($data, $intent);
        $definition = $this->kinds->definition($intent['kind']);
        $class = is_array($definition) ? ($definition['admission'] ?? null) : null;
        $adapter = is_string($class) ? app($class) : null;
        if (! $adapter instanceof OperationChildAdmission) {
            throw new ResourceOperationException('operation.child_forbidden', 'The owning child admission is unavailable.', 422);
        }
        $child = $this->idempotency->duringChild($parent, $intent, static fn (): Operation => $adapter->admit($parent, $intent));
        $child->refresh();
        if ($child->parent_operation_id !== $parent->id || $child->child_key !== $key || $child->kind !== $intent['kind'] || $child->node_id !== $intent['node_id']) {
            throw new ResourceOperationException('operation.child_forbidden', 'The owning admission did not create the bound child.', 422);
        }
        $inherited = $parent->run_constraints ?? [];
        $constraints = $child->run_constraints ?? [];
        foreach ($inherited as $name => $parameters) {
            if (isset($constraints[$name]) && ! OperationChildIntent::same($constraints[$name], $parameters)) {
                throw new ResourceOperationException('operation.child_forbidden', 'A child cannot weaken an inherited constraint.', 422);
            }
            $constraints[$name] = $parameters;
        }
        $this->constraints->validate($child, $constraints);
        $child->forceFill(['run_constraints' => $constraints])->save();
        $snapshot = ['intent' => $intent, 'admitted' => $this->identity($child)];
        $child->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();

        return ['operation' => $child, 'created' => true];
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $intent
     */
    private function assertPayload(array $data, array $intent): void
    {
        foreach (['kind', 'node_id', 'input'] as $field) {
            if (array_key_exists($field, $data) && ! OperationChildIntent::same([$data[$field]], [$intent[$field] ?? null])) {
                throw new ResourceOperationException('operation.idempotency_conflict', 'The child key has another admitted intent.', 409);
            }
        }
    }

    /** @param ChildIntent $intent
     * @param  array<string,mixed>  $prepared
     */
    private function authorize(Operation $parent, array $intent, array $prepared): void
    {
        $definition = $this->kinds->definition($parent->kind);
        if (! is_array($definition)) {
            throw new LogicException('A child parent must be a Swamp Operation.');
        }
        foreach ($definition['children'] ?? [] as $child) {
            if ($child['kind'] !== $intent['kind']) {
                continue;
            }
            $root = ['operation' => ['node_id' => $parent->node_id], 'input' => $parent->input, 'state' => $parent->state ?? [], 'intent' => $prepared];
            if (! Arr::has($root, $child['node_from'])) {
                continue;
            }
            $nodes = Arr::get($root, $child['node_from']);
            $nodes = is_array($nodes) && array_is_list($nodes) ? $nodes : [$nodes];
            if (! in_array($intent['node_id'], $nodes, true)) {
                continue;
            }
            if (isset($child['bind'])) {
                $input = [];
                foreach ($child['bind'] as $parameter => $path) {
                    if (! Arr::has($root, $path)) {
                        throw new ResourceOperationException('operation.child_forbidden', 'An admitted child input binding is missing.', 422);
                    }
                    Arr::set($input, $parameter, Arr::get($root, $path));
                }
                if (! OperationChildIntent::same($input, $intent['input'])) {
                    continue;
                }
            }

            return;
        }
        throw new ResourceOperationException('operation.child_forbidden', 'The child does not match its parent contract.', 422);
    }

    /** @return array<string,mixed> */
    private function identity(Operation $child): array
    {
        return ['parent_operation_id' => $child->parent_operation_id, 'key' => $child->child_key,
            'kind' => $child->kind, 'node_id' => $child->node_id, 'target_type' => $child->target_type,
            'target_id' => $child->target_id, 'input' => $child->input, 'run_constraints' => $child->run_constraints ?? []];
    }

    public function verify(Operation $parent, Operation $child): void
    {
        $snapshot = $child->child_admission;
        if ($child->parent_operation_id !== $parent->id || $child->child_key === null || $snapshot === null
            || $child->child_fingerprint !== OperationChildIntent::fingerprint($snapshot)
            || ! is_array($snapshot['admitted'] ?? null)
            || ! OperationChildIntent::same($snapshot['admitted'], $this->identity($child))) {
            throw new ResourceOperationException('operation.child_unproven', 'The child does not match its recorded admission.', 409);
        }
    }

    /** A cancelled ancestor admits only a stamped compensation branch. */
    private function assertAncestors(Operation $operation): void
    {
        while ($operation->parent_operation_id !== null) {
            $parent = Operation::query()->find($operation->parent_operation_id);
            if ($parent === null || $parent->status->terminal()) {
                throw new ResourceOperationException('operation.child_unproven', 'The parent no longer holds this child admission.', 409);
            }
            $this->verify($parent, $operation);
            if ($parent->cancel_requested_at !== null && ! $this->compensationAllowed($parent, $operation->kind)) {
                throw new ResourceOperationException('operation.cancelled', 'An ancestor cancelled this child branch.', 409);
            }
            $operation = $parent;
        }
    }

    private function compensationAllowed(Operation $parent, string $kind): bool
    {
        $definition = $this->kinds->definition($parent->kind);
        if (! is_array($definition) || ! in_array($kind, $definition['compensations'] ?? [], true)
            || ! isset($definition['cancellation_boundary'])) {
            return false;
        }
        try {
            $this->requireSucceeded($parent, $definition['cancellation_boundary']);
            return true;
        } catch (ResourceOperationException) {
            return false;
        }
    }

    public function assertRun(Operation $operation, bool $checkConstraints = true): void
    {
        $this->assertAncestors($operation);
        if ($checkConstraints) {
            $this->constraints->assertAllowed($operation);
        }
    }

    /** All effects sharing an ancestor's target hold share its attempt lease too. */
    public function executionLeaseId(Operation $operation): int
    {
        $targetType = $operation->target_type;
        $targetId = $operation->target_id;
        $leaseId = $operation->id;
        while ($operation->parent_operation_id !== null) {
            $parent = Operation::query()->find($operation->parent_operation_id);
            if ($parent === null) {
                break;
            }
            $this->verify($parent, $operation);
            if ($parent->target_type === $targetType && $parent->target_id === $targetId && $parent->lock_key !== null) {
                $leaseId = $parent->id;
            }
            $operation = $parent;
        }
        return $leaseId;
    }

    public function requireSucceeded(Operation $parent, string $key): Operation
    {
        $child = $this->child($parent, $key);
        if ($child->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException('operation.child_unproven', 'The required child has not succeeded.', 409);
        }

        return $child;
    }

    public function child(Operation $parent, string $key): Operation
    {
        $child = $parent->children()->where('child_key', $key)->first();
        if ($child === null) {
            throw new ResourceOperationException('operation.child_unproven', 'This parent has no child with that key.', 409);
        }
        $this->verify($parent, $child);

        return $child;
    }

    /** @return list<array<string,mixed>> */
    public function receipts(Operation $parent): array
    {
        return array_values($parent->children()->get()->map(function (Operation $child) use ($parent): array {
            $this->verify($parent, $child);

            return ['id' => $child->id, 'key' => $child->child_key, 'kind' => $child->kind, 'node_id' => $child->node_id,
                'status' => $child->status->value, 'error_code' => $child->error_code,
                'result' => $child->result === null ? null : $this->contract->result($child, $child->result)];
        })->all());
    }

    /** @param list<string> $keys
     * @return list<array{key:string,id:int,fingerprint:string}>
     */
    public function waitFor(Operation $parent, array $keys): array
    {
        $tuples = [];
        foreach ($keys as $key) {
            $child = $this->child($parent, $key);
            $tuples[] = ['key' => $key, 'id' => $child->id, 'fingerprint' => (string) $child->child_fingerprint];
        }

        return $tuples;
    }

    /** Pure by default so discovery cannot write assistance state. */
    public function ready(Operation $parent, bool $flagAssistance = false): bool
    {
        $record = ValidatedData::object($parent->state['executor_wait'] ?? []);
        $wait = $record['children'] ?? null;
        try {
            if (! is_array($wait) || $wait === []) {
                throw new ResourceOperationException('operation.child_unproven', 'The waiting child evidence is missing.', 409);
            }
            $ready = true;
            foreach ($wait as $tuple) {
                if (! is_array($tuple) || ! is_string($tuple['key'] ?? null)) {
                    throw new ResourceOperationException('operation.child_unproven', 'The waiting child evidence is invalid.', 409);
                }
                $child = $this->child($parent, $tuple['key']);
                if ($child->id !== ($tuple['id'] ?? null) || $child->child_fingerprint !== ($tuple['fingerprint'] ?? null)) {
                    throw new ResourceOperationException('operation.child_unproven', 'The waiting child identity changed.', 409);
                }
                if ($child->status === OperationStatus::Queued && ! is_array($this->kinds->definition($child->kind))) {
                    throw new ResourceOperationException('operation.kind_unavailable', 'A waiting child kind is unavailable.', 409);
                }
                $ready = $ready && $child->status->terminal();
            }
            return $ready;
        } catch (ResourceOperationException $exception) {
            if ($flagAssistance) {
                $flag = ['error_code' => $exception->errorCode];
                if (($parent->state['executor_assistance'] ?? null) !== $flag) {
                    app(OperationStore::class)->change($parent, ['state' => [...($parent->state ?? []), 'executor_assistance' => $flag]]);
                }
            }
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function reentry(Operation $parent): array
    {
        $record = ValidatedData::object($parent->state['executor_wait'] ?? []);
        $children = [];
        foreach (is_array($record['children'] ?? null) ? $record['children'] : [] as $tuple) {
            $child = $this->child($parent, ValidatedData::string($tuple['key']));
            $children[] = [...$tuple, 'kind' => $child->kind, 'status' => $child->status->value, 'error_code' => $child->error_code,
                'result' => $child->result === null ? null : $this->contract->result($child, $child->result)];
        }
        return [...$record, 'children' => $children];
    }

    public function assertNoPending(Operation $parent): void
    {
        if ($parent->children()->whereNotIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])->exists()) {
            throw new ResourceOperationException('operation.children_running', 'Settle admitted children before completing their parent.', 409);
        }
    }

    /** @param list<string> $kinds */
    public function assertSucceeded(Operation $parent, array $kinds): void
    {
        $context = $this->context($parent);
        foreach ($kinds as $kind) {
            $keys = [];
            foreach ($context['children'] as $intent) {
                if ($intent['kind'] === $kind) {
                    $keys[] = $intent['key'];
                    $this->requireSucceeded($parent, $intent['key']);
                }
            }
            $children = $parent->children()->where('kind', $kind)->get();
            if ($children->isEmpty() && $keys === []) {
                throw new ResourceOperationException('operation.child_unproven', 'A required child kind is missing.', 409);
            }
            foreach ($children as $child) {
                $this->requireSucceeded($parent, (string) $child->child_key);
            }
        }
    }
}
