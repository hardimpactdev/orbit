<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\GrantGatewayRoleAccessAction;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;

/** Node role ordering and proof from immutable plans, transport receipts and Kit B child identity. */
final readonly class NodeRoleOperationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private OperationChildren $children) {}

    public function context(Operation $operation): array
    {
        $descriptors = [$this->descriptor(['key' => 'prepare-role', 'transport' => 'gateway', 'name' => 'node.prepare-role',
            'input' => $this->prepareInput($operation)])];
        foreach ($this->eligible($operation) as $step) {
            if ($step['type'] === 'run') {
                $descriptors[] = $this->descriptor($step);
            }
        }
        $descriptors[] = $this->descriptor(['key' => 'role-result', 'transport' => 'gateway', 'name' => 'node.role-result', 'input' => []]);

        $waitUntil = $this->withdrawalAt($operation);

        $result = $waitUntil === null ? [] : ['role_wait_until' => $waitUntil];
        if ($this->networkRollbackNeeded($operation)) {
            $result['role_rollback_required'] = true;
        }
        if ($operation->cancel_requested_at !== null && ($operation->state['node_role_prepared'] ?? false) === true
            && ! $operation->children()->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->exists()
            && (! $this->networkRollbackNeeded($operation) || $operation->children()->where('child_key', 'role-network-rollback')->exists())) {
            $result = [...$result, ...$this->cancelledResult($operation)];
        }

        return ['descriptors' => $descriptors, 'result' => $result];
    }

    public function childContext(Operation $parent): array
    {
        $intents = [];
        $nodes = [];
        $recorded = $parent->state['child_intents'] ?? [];
        if (! is_array($recorded) || ! array_is_list($recorded)) {
            throw $this->unproven();
        }
        foreach ($this->eligible($parent) as $step) {
            if ($step['type'] !== 'child') {
                continue;
            }
            $intent = ['key' => ValidatedData::string($step['key']), 'kind' => ValidatedData::string($step['kind']),
                'node_id' => ValidatedData::integer($step['node_id']), 'input' => ValidatedData::object($step['input'])];
            if (! array_any($recorded, static fn (mixed $entry): bool => is_array($entry) && OperationChildIntent::same($entry, $intent))) {
                throw $this->unproven();
            }
            $nodes[] = $intent['node_id'];
            $intents[] = $intent;
        }

        if ($this->networkRollbackNeeded($parent)) {
            $rollback = ['key' => 'role-network-rollback', 'kind' => 'node.network_rollback', 'node_id' => $parent->node_id, 'input' => []];
            if (! array_any($recorded, static fn (mixed $entry): bool => is_array($entry) && OperationChildIntent::same($entry, $rollback))) {
                throw $this->unproven();
            }
            $intents[] = $rollback;
            $nodes[] = $parent->node_id;
        }

        return ['intent' => ['node_ids' => array_values(array_unique($nodes))], 'children' => $intents];
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
        if ($run['key'] === 'role-result') {
            $this->result($operation);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'ci-runner.host') {
            $step = array_find($this->plan($operation), static fn (array $step): bool => $step['key'] === $run['key']);
            $expected = is_array($step) ? ValidatedData::object($step['result'] ?? []) : [];
            if (isset($receipt['error_code']) || ($receipt['outcome'] ?? null) !== ($expected['outcome'] ?? null)) {
                return ['error_code' => 'ci-runner.host_failed'];
            }
        }
        if ($run['name'] === 'node.grant-gateway-access' && ($receipt['access_granted'] ?? null) === true && ! isset($receipt['error_code'])) {
            app(GrantGatewayRoleAccessAction::class)->execute(Node::query()->findOrFail($operation->node_id));
        }

        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            if ($this->networkRollbackNeeded($operation)) {
                $expected = $operation->cancel_requested_at !== null ? $this->cancelledResult($operation) : $this->result($operation);
                if (! OperationChildIntent::same($expected, $outcome->result ?? [])) {
                    throw $this->unproven();
                }
            }

            return;
        }
        $result = $this->result($operation);
        if (isset($result['error_code']) || $outcome->result === null || ! OperationChildIntent::same($result, $outcome->result)) {
            throw $this->unproven();
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @return array<string,mixed> */
    public function result(Operation $operation): array
    {
        if (($operation->state['node_role_prepared'] ?? false) !== true) {
            throw $this->unproven();
        }
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        if (! is_bool($intent['created'] ?? null)) {
            throw $this->unproven();
        }
        $result = ['role_proven' => true, 'assignment_id' => $intent['assignment_id'], 'created' => $intent['created']];
        $followUps = [];
        foreach ($this->plan($operation) as $step) {
            $proof = $this->proof($operation, $step);
            if ($proof['status'] === 'skipped') {
                continue;
            }
            if ($proof['status'] === 'pending') {
                throw $this->unproven();
            }
            if ($proof['status'] === 'failed') {
                if (($step['optional'] ?? false) !== true) {
                    if ($this->networkRollbackNeeded($operation) && ! $this->networkRollbackSucceeded($operation)) {
                        return [...$result, 'error_code' => 'vpn.peer_rollback_failed', 'failed_step' => 'role-network-rollback'];
                    }

                    return [...$result, 'error_code' => $proof['error_code'], 'failed_step' => $step['key']];
                }
                $followUps[] = ValidatedData::string($step['key']).':'.$proof['error_code'];
            }
        }
        if ($followUps !== []) {
            $result['follow_up'] = implode(', ', $followUps);
        }

        return $result;
    }

    private function networkRollbackNeeded(Operation $operation): bool
    {
        if (($operation->state['role_first_managed'] ?? false) !== true) {
            return false;
        }
        $child = $operation->children()->where('child_key', 'role-network')->first();
        if ($child === null || $child->status !== OperationStatus::Succeeded) {
            return false;
        }
        $this->children->verify($operation, $child);
        if (($child->result['retained'] ?? null) !== true) {
            return false;
        }
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $commit = ValidatedData::object($runs['role-network-commit'] ?? []);
        if (is_array($commit['result'] ?? null) && ! isset($commit['result']['error_code'])) {
            return false;
        }
        if ($operation->cancel_requested_at !== null) {
            return true;
        }

        return array_any($this->plan($operation), fn ($step) => $this->proof($operation, $step)['status'] === 'failed' && ($step['optional'] ?? false) !== true);
    }

    private function networkRollbackSucceeded(Operation $operation): bool
    {
        $child = $this->children->child($operation, 'role-network-rollback');
        if ($child->kind !== 'node.network_rollback' || $child->node_id !== $operation->node_id || ! $child->status->terminal()) {
            throw $this->unproven();
        }

        return $child->status === OperationStatus::Succeeded;
    }

    /** @return array<string,mixed> */
    private function cancelledResult(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $restored = ! $this->networkRollbackNeeded($operation) || $this->networkRollbackSucceeded($operation);

        return ['role_proven' => true, 'assignment_id' => $intent['assignment_id'], 'created' => $intent['created'],
            'error_code' => $restored ? 'operation.cancelled' : 'vpn.peer_rollback_failed',
            'failed_step' => $restored ? 'operation' : 'role-network-rollback'];
    }

    /** @return array<string,mixed> */
    private function prepareInput(Operation $operation): array
    {
        if ($operation->kind === 'node.role_relocate') {
            return ['role' => $operation->input['role'], 'converge' => true];
        }
        if (in_array($operation->kind, ['node.role_converge', 'node.role_relocate_source', 'node.role_relocate_target'], true)) {
            return ['assignment_id' => $operation->input['assignment_id']];
        }
        if ($operation->kind === 'node.role_remove') {
            return ['role' => $operation->input['role'], 'purge_data' => $operation->input['purge_data'], 'offline' => $operation->input['offline']];
        }

        return ['role' => $operation->input['role'], 'converge' => $operation->input['converge']];
    }

    /** @return list<array<string,mixed>> */
    private function eligible(Operation $operation): array
    {
        $eligible = [];
        $waitUntil = $this->withdrawalAt($operation);
        foreach ($this->plan($operation) as $step) {
            if ($step['key'] !== 'role-relocation-target' && $waitUntil !== null && Carbon::now()->getTimestampMs() < $waitUntil) {
                break;
            }
            $proof = $this->proof($operation, $step);
            if ($proof['status'] === 'skipped') {
                continue;
            }
            $eligible[] = $step;
            if ($proof['status'] === 'pending' || ($proof['status'] === 'failed' && ($step['optional'] ?? false) !== true)) {
                break;
            }
        }

        return $eligible;
    }

    private function withdrawalAt(Operation $operation): ?int
    {
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        if ($operation->kind !== 'node.role_relocate_source' || ($intent['role'] ?? null) !== 'websocket') {
            return null;
        }
        $target = $operation->children()->where('child_key', 'role-relocation-target')->first();
        if ($target === null || $target->status !== OperationStatus::Succeeded) {
            return null;
        }
        $this->children->verify($operation, $target);
        if ($target->finished_at === null) {
            throw $this->unproven();
        }

        return $target->finished_at->addSeconds(PrivateDnsAnswerExpiry::WithdrawalGraceSeconds)->getTimestampMs();
    }

    /** @param array<string,mixed> $step
     * @return array{status:'pending'|'failed'|'succeeded'|'skipped',error_code:?string}
     */
    private function proof(Operation $operation, array $step): array
    {
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        if (isset($step['when'])) {
            $when = ValidatedData::object($step['when']);
            $prior = $runs[ValidatedData::string($when['key'])] ?? null;
            if (! is_array($prior) || ! is_array($prior['result'] ?? null)) {
                return ['status' => 'pending', 'error_code' => null];
            }
            foreach (ValidatedData::object($when['result']) as $name => $expected) {
                if (($prior['result'][$name] ?? null) !== $expected) {
                    return ['status' => 'skipped', 'error_code' => null];
                }
            }
        }
        if ($step['type'] === 'child') {
            $child = $operation->children()->where('child_key', $step['key'])->first();
            if ($child === null) {
                return ['status' => 'pending', 'error_code' => null];
            }
            $this->children->verify($operation, $child);
            $intent = ValidatedData::object($child->child_admission['intent'] ?? []);
            $expected = ['key' => $step['key'], 'kind' => $step['kind'], 'node_id' => $step['node_id'], 'input' => $step['input']];
            if (! OperationChildIntent::same($intent, $expected)) {
                throw $this->unproven();
            }
            if (! $child->status->terminal()) {
                return ['status' => 'pending', 'error_code' => null];
            }
            $error = $child->status === OperationStatus::Succeeded ? null : ($child->error_code ?? 'node_role.child_failed');
            $receipt = $child->result ?? [];
        } else {
            $run = $runs[ValidatedData::string($step['key'])] ?? null;
            if (! is_array($run) || ($run['name'] ?? null) !== $step['name'] || ($run['transport'] ?? null) !== $step['transport'] || ! is_array($run['result'] ?? null)) {
                return ['status' => 'pending', 'error_code' => null];
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
            if (($step['name'] ?? null) === 'php.install-build' && ! in_array($receipt['outcome'] ?? null, ['current', 'applied'], true)) {
                throw $this->unproven();
            }
        }

        return ['status' => $error === null ? 'succeeded' : 'failed', 'error_code' => $error];
    }

    /** @return list<array<string,mixed>> */
    private function plan(Operation $operation): array
    {
        $plan = $operation->input['_role_plan'] ?? [];
        if (! is_array($plan) || ! array_is_list($plan)) {
            throw $this->unproven();
        }

        return array_map(ValidatedData::object(...), $plan);
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
        return new ResourceOperationException('operation.completion_unproven', 'The role requires its recorded step and child proof.', 409);
    }
}
