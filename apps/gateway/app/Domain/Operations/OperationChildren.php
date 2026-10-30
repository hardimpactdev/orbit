<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
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
        if ($parent->cancel_requested_at !== null && ! $this->contract->cancellationAllowsRun($parent)
            && ! $this->compensationAllowed($parent, $intent['kind'])) {
            throw new ResourceOperationException('operation.cancelled', 'The parent was cancelled before child admission.', 409);
        }
        $this->authorize($parent, $intent, $context['intent']);
        $this->assertPayload($data, $intent);
        $this->assertSettledCapacity($parent, $intent);
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
        $snapshot = $child->child_admission['admitted'] ?? null;
        $admittedNode = is_array($snapshot) ? ($snapshot['node_id'] ?? null) : null;

        return ['parent_operation_id' => $child->parent_operation_id, 'key' => $child->child_key,
            'kind' => $child->kind, 'node_id' => $child->node_id ?? $admittedNode, 'target_type' => $child->target_type,
            'target_id' => $child->target_id, 'input' => $child->input, 'run_constraints' => $child->run_constraints ?? []];
    }

    public function verify(Operation $parent, Operation $child): void
    {
        $snapshot = $child->child_admission;
        if ($child->parent_operation_id !== $parent->id || $child->child_key === null || $snapshot === null
            || $child->child_fingerprint !== OperationChildIntent::fingerprint($snapshot)
            || ! is_array($snapshot['admitted'] ?? null)
            || ($child->node_id === null && is_int($snapshot['admitted']['node_id'] ?? null) && Node::query()->whereKey($snapshot['admitted']['node_id'])->exists())
            || ! OperationChildIntent::same($snapshot['admitted'], $this->identity($child))) {
            throw new ResourceOperationException('operation.child_unproven', 'The child does not match its recorded admission.', 409);
        }
    }

    /** Original Node identity survives the live foreign key's ON DELETE SET NULL. */
    public function nodeId(Operation $operation): ?int
    {
        if ($operation->parent_operation_id === null) {
            $snapshot = $operation->state['executor_target'] ?? null;
            if (! is_array($snapshot)) {
                return $operation->node_id;
            }
            $id = $snapshot['node_id'] ?? null;
            if (($snapshot['target_type'] ?? null) !== $operation->target_type || ($snapshot['target_id'] ?? null) !== $operation->target_id
                || ($operation->node_id !== null && $operation->node_id !== $id)
                || ($operation->node_id === null && $id !== null && Node::query()->whereKey($id)->exists())) {
                throw new ResourceOperationException('operation.child_unproven', 'The Operation target no longer matches admission.', 409);
            }

            return $id === null ? null : ValidatedData::integer($id);
        }
        $parent = Operation::query()->find($operation->parent_operation_id);
        if ($parent === null) {
            throw new ResourceOperationException('operation.child_unproven', 'The stamped parent is unavailable.', 409);
        }
        $this->verify($parent, $operation);
        $admitted = ValidatedData::object($operation->child_admission['admitted'] ?? []);
        $id = $admitted['node_id'] ?? null;

        return $id === null ? null : ValidatedData::integer($id);
    }

    /** Every ancestor must still own the stamped child admission. Cancellation is cascaded locally. */
    private function assertAncestors(Operation $operation): void
    {
        while ($operation->parent_operation_id !== null) {
            $parent = Operation::query()->find($operation->parent_operation_id);
            if ($parent === null || $parent->status->terminal()) {
                throw new ResourceOperationException('operation.child_unproven', 'The parent no longer holds this child admission.', 409);
            }
            $this->verify($parent, $operation);
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

            return ['id' => $child->id, 'key' => $child->child_key, 'kind' => $child->kind, 'node_id' => $this->nodeId($child),
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
        try {
            $record = $parent->state['executor_wait'] ?? null;
            $wait = is_array($record) ? ($record['children'] ?? null) : null;
            if (! is_array($wait) || ! array_is_list($wait) || $wait === []) {
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
            $tuple = ValidatedData::object($tuple);
            $child = $this->child($parent, ValidatedData::string($tuple['key']));
            $children[] = [...$tuple, 'kind' => $child->kind, 'status' => $child->status->value, 'error_code' => $child->error_code,
                'result' => $child->result === null ? null : $this->contract->result($child, $child->result)];
        }

        return [...$record, 'children' => $children];
    }

    /** A flagged parent can be explicitly abandoned only after every provable child settles. */
    public function hasProvenNonterminalChild(Operation $parent): bool
    {
        foreach ($parent->children()->whereNotIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])->get() as $child) {
            try {
                $this->verify($parent, $child);

                return true;
            } catch (ResourceOperationException) {
                // An invalid admission is the assistance case, never proof of active child ownership.
            }
        }

        return false;
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

    /** @param list<string> $kinds
     * @param  array<string,mixed>  $result
     * @return list<array{child_id:int,reason:string,key:string,kind:string}>
     */
    public function assertSettled(Operation $parent, array $kinds, array $result, bool $requireExpected = true): array
    {
        $expectedChildren = $requireExpected ? $this->context($parent)['children'] : [];
        foreach ($expectedChildren as $intent) {
            if (! in_array($intent['kind'], $kinds, true)) {
                throw new ResourceOperationException('operation.child_unproven', 'A recorded child kind is not allowed by the settled contract.', 409);
            }
            $child = $this->child($parent, $intent['key']);
            $admittedIntent = $child->child_admission['intent'] ?? null;
            if (! is_array($admittedIntent) || ! OperationChildIntent::same($intent, $admittedIntent)) {
                throw new ResourceOperationException('operation.child_unproven', 'The child does not match its recorded intent.', 409);
            }
        }
        $children = $parent->children()->orderBy('id')->get();
        foreach ($children as $child) {
            if (! in_array($child->kind, $kinds, true)) {
                throw new ResourceOperationException('operation.child_unproven', 'An admitted child kind is not allowed by the settled contract.', 409);
            }
            $this->verify($parent, $child);
            if (! $child->status->terminal()) {
                throw new ResourceOperationException('operation.child_unproven', 'A required child has not settled.', 409);
            }
        }
        $failedChildren = $children->filter(static fn (Operation $child): bool => in_array($child->status, [OperationStatus::Failed, OperationStatus::Cancelled], true))->values();
        $failed = $failedChildren->map(static fn (Operation $child): int => $child->id)->all();
        $skips = $requireExpected ? ($result['skipped'] ?? []) : $failedChildren->map(static fn (Operation $child): array => ['child_id' => $child->id, 'reason' => $child->status->value])->all();
        if (! is_array($skips) || ! array_is_list($skips)) {
            throw new ResourceOperationException('operation.child_unproven', 'The skipped child evidence is invalid.', 409);
        }
        $acknowledged = [];
        $canonical = [];
        foreach ($skips as $skip) {
            if (! is_array($skip) || ! is_int($skip['child_id'] ?? null) || ! is_string($skip['reason'] ?? null)
                || array_diff(array_keys($skip), ['child_id', 'reason', 'key', 'kind']) !== []
                || trim($skip['reason']) === '' || strlen($skip['reason']) > 500 || in_array($skip['child_id'], $acknowledged, true)) {
                throw new ResourceOperationException('operation.child_unproven', 'The skipped child evidence is invalid.', 409);
            }
            $child = $parent->children()->whereKey($skip['child_id'])->first();
            if ($child === null || ! in_array($child->status, [OperationStatus::Failed, OperationStatus::Cancelled], true)) {
                throw new ResourceOperationException('operation.child_unproven', 'The skipped child does not match this parent failure.', 409);
            }
            $this->verify($parent, $child);
            $acknowledged[] = $child->id;
            $canonical[] = ['child_id' => $child->id, 'reason' => $skip['reason'], 'key' => (string) $child->child_key, 'kind' => $child->kind];
        }
        if (array_diff($failed, $acknowledged) !== []) {
            throw new ResourceOperationException('operation.child_unproven', 'A failed child is missing from skipped evidence.', 409);
        }

        return $canonical;
    }

    /** Reserve a complete derived failure receipt before admitting another child.
     * @param  ChildIntent  $intent
     */
    private function assertSettledCapacity(Operation $parent, array $intent): void
    {
        $definition = $this->kinds->definition($parent->kind);
        if (! is_array($definition) || ! isset($definition['completion_contract'])) {
            return;
        }
        $contract = OperationCompletionContracts::select($parent, $definition['completion_contract']);
        if (($contract['children_settled'] ?? []) === []) {
            return;
        }
        $schema = ValidatedData::object(OperationResultFields::skippedField($definition['result_fields'])['list_of']);
        $skips = [];
        foreach ($parent->children()->get() as $child) {
            $skips[] = array_intersect_key(['child_id' => PHP_INT_MAX, 'reason' => 'cancelled', 'key' => $child->child_key, 'kind' => $child->kind], $schema);
        }
        $skips[] = array_intersect_key(['child_id' => PHP_INT_MAX, 'reason' => 'cancelled', 'key' => $intent['key'], 'kind' => $intent['kind']], $schema);
        OperationResultFields::filter($definition['result_fields'], ['skipped' => $skips]);
    }
}
