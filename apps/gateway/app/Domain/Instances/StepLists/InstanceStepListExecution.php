<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Reads immutable list bindings and verifies proven receipts. Parents decide rollback.
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 */
final readonly class InstanceStepListExecution implements OperationCompletion, OperationFamilyExecution
{
    public function __construct(private ProjectLifecycleStepStore $steps, private StartInstanceStepListAction $admission, private InstanceStepListBudget $budget) {}

    public function instance(Operation $operation): Instance
    {
        $instance = Instance::query()->with('project')->findOrFail($operation->target_id);
        $phase = LifecyclePhase::tryFrom(ValidatedData::string($operation->input['list'] ?? ''));
        if ($operation->target_type !== 'instance' || $operation->node_id !== $instance->node_id
            || ($operation->input['instance_id'] ?? null) !== $instance->id
            || ($operation->input['source'] ?? null) !== 'project' || $phase === null
            || $instance->placedOnAppProd() || $instance->checkout_path === ''
            || ! hash_equals($this->admission->configurationDigest($instance->project, $phase), ValidatedData::string($operation->input['configuration_digest'] ?? ''))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Project step list does not match admitted intent.', 422);
        }

        return $instance;
    }

    public function context(Operation $operation): array
    {
        $instance = $this->instance($operation);
        $phase = LifecyclePhase::from(ValidatedData::string($operation->input['list']));
        $steps = $this->steps->ordered($instance->project, $phase);
        $descriptors = [];
        if ($steps !== []) {
            $descriptors[] = $this->descriptor('lifecycle.scope', 'lifecycle_scope', 'agent', 'tool.inspect-vp-scope', []);
            $binary = InstanceExecutionEvidence::result($operation, 'lifecycle.scope')['binary'] ?? null;
            if (is_string($binary)) {
                foreach ($steps as $step) {
                    $budgetKey = 'budget.'.$phase->value.'.'.$step->name;
                    $descriptors[] = $this->descriptor($budgetKey, $phase->value.'.'.$step->name.'.budget', 'gateway', 'instance.step-budget', ['instance_id' => $instance->id, 'step' => $step->name]);
                    $commandTimeout = InstanceExecutionEvidence::result($operation, $budgetKey)['command_timeout_seconds'] ?? null;
                    if (! is_int($commandTimeout)) {
                        continue;
                    }
                    $seeded = $phase === LifecyclePhase::Setup && $instance->seed_path !== $instance->checkout_path;
                    $descriptors[] = $this->descriptor($phase->value.'.'.$step->name, $phase->value.'.'.$step->name,
                        'agent', 'instance.run-lifecycle-step', [
                            'checkout' => $instance->checkout_path,
                            'program' => ['$secret' => 'instance.step_list', 'id' => $instance->id],
                            'step' => $step->name, 'timeout_seconds' => $commandTimeout,
                            'environment' => ['VP_HOME' => dirname($binary, 2),
                                'ORBIT_SEED_PATH' => $seeded ? ($instance->seed_path ?? '') : '',
                                'ORBIT_SEED_COMMIT' => $seeded ? ($instance->seed_commit ?? '') : ''],
                        ], false);
                }
            }
        }
        $descriptors[] = $this->descriptor('lifecycle.receipt', 'lifecycle_receipt', 'gateway', 'instance.step-list-receipt', ['instance_id' => $instance->id]);

        return ['descriptors' => $descriptors];
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
        $instance = $this->instance($operation);
        $phase = LifecyclePhase::from(ValidatedData::string($operation->input['list']));
        if ($run['key'] === 'lifecycle.scope') {
            return;
        }
        if ($this->steps->ordered($instance->project, $phase) !== []) {
            $this->provenScope($operation);
        }
        foreach ($this->steps->ordered($instance->project, $phase) as $step) {
            $key = $phase->value.'.'.$step->name;
            $budgetKey = 'budget.'.$key;
            if ($run['key'] === $budgetKey) {
                return;
            }
            $this->provenBudget($operation, $budgetKey, $key.'.budget');
            if ($run['key'] === $key) {
                if ($this->budget->remaining($operation) === 0) {
                    throw new ResourceOperationException('command.deadline_exceeded', 'The list deadline expired before this command started.', 504);
                }

                return;
            }
            $this->proven($operation, $key, 'instance.run-lifecycle-step');
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if ($run['name'] === 'instance.run-lifecycle-step') {
            $budget = InstanceExecutionEvidence::result($operation, 'budget.'.$run['key']) ?? [];

            return [...$receipt, 'budget_seconds' => $budget['budget_seconds'], 'timeout_seconds' => $budget['timeout_seconds']];
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    public function result(Operation $operation): array
    {
        $instance = $this->instance($operation);
        $steps = $this->steps->ordered($instance->project, LifecyclePhase::from(ValidatedData::string($operation->input['list'])));
        if ($steps !== []) {
            $this->provenScope($operation);
        }
        foreach ($steps as $step) {
            $key = ValidatedData::string($operation->input['list']).'.'.$step->name;
            $this->provenBudget($operation, 'budget.'.$key, $key.'.budget');
            $this->proven($operation, ValidatedData::string($operation->input['list']).'.'.$step->name, 'instance.run-lifecycle-step');
        }

        return ['instance_id' => $instance->id, 'outcome' => 'succeeded', 'failed_step' => null,
            'error_code' => null, 'no_op' => $steps === []];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            if ($outcome->result !== $this->result($operation)) {
                throw $this->unproven();
            }

            return;
        }
        if (($outcome->result['instance_id'] ?? null) !== $operation->target_id) {
            throw $this->unproven();
        }
        if ($outcome->status === OperationStatus::Cancelled) {
            if (($outcome->result['outcome'] ?? null) !== 'cancelled') {
                throw $this->unproven();
            }

            return;
        }
        $classification = $outcome->result['outcome'] ?? 'unconfirmed';
        if ($classification === 'deadline' && $outcome->errorCode === 'command.deadline_exceeded') {
            $key = $outcome->result['failed_step'] ?? null;
            foreach ($this->context($operation)['descriptors'] as $descriptor) {
                if ($descriptor['step'] !== $key) {
                    continue;
                }
                $run = InstanceExecutionEvidence::run($operation, $descriptor['key']);
                $receipt = $run['result'] ?? null;
                if (($run['name'] ?? null) === 'instance.step-budget' && ($receipt['error_code'] ?? null) === 'command.deadline_exceeded') {
                    return;
                }
                if (($run['name'] ?? null) === 'instance.run-lifecycle-step'
                    && ($receipt['timed_out'] ?? false) === true
                    && is_int($receipt['budget_seconds'] ?? null) && is_int($receipt['timeout_seconds'] ?? null)
                    && $receipt['budget_seconds'] < $receipt['timeout_seconds']) {
                    return;
                }
                if (($run['started'] ?? false) === false && $this->budget->remaining($operation) === 0) {
                    return;
                }
            }
            throw $this->unproven();
        }
        if ($classification === 'unconfirmed') {
            return;
        }
        $key = $outcome->result['failed_step'] ?? null;
        $run = is_string($key) ? (InstanceExecutionEvidence::run($operation, $key)) : null;
        $receipt = $run['result'] ?? [];
        if (($run['name'] ?? null) !== 'instance.run-lifecycle-step' || ($run['transport'] ?? null) !== 'agent'
            || ($run['step'] ?? null) !== $key
            || ! $operation->steps()->where('name', $key)->where('status', 'failed')->exists()) {
            throw $this->unproven();
        }
        $phase = ValidatedData::string($operation->input['list']);
        $expected = match (true) {
            ($receipt['error_code'] ?? null) === 'instance.lifecycle_busy', ($receipt['exit_code'] ?? null) === 75 => ['busy', 'instance.lifecycle_busy'],
            in_array($receipt['exit_code'] ?? null, [126, 127], true) => ['missing', 'instance.'.$phase.'_step_unavailable'],
            ($receipt['timed_out'] ?? false) === true, in_array($receipt['exit_code'] ?? null, [1, 124], true) => ['failed', 'instance.'.$phase.'_step_failed'],
            default => ['unconfirmed', 'instance.'.$phase.'_step_failed'],
        };
        if ($classification !== $expected[0] || $outcome->errorCode !== $expected[1]) {
            throw $this->unproven();
        }
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->assertCompletion($operation, $outcome);
        if ($operation->input['list'] !== 'setup') {
            return;
        }
        $instance = Instance::query()->whereKey($operation->target_id)->where('node_id', $operation->node_id)->first();
        if ($instance === null) {
            return;
        }
        if ($outcome->status === OperationStatus::Succeeded) {
            $instance->update(['failed_step' => null, 'error_code' => null, 'first_setup_pending' => false]);
        } else {
            $instance->update(['failed_step' => 'setup', 'error_code' => $outcome->errorCode]);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function provenBudget(Operation $operation, string $key, string $step): void
    {
        $run = InstanceExecutionEvidence::run($operation, $key);
        if (($run['name'] ?? null) !== 'instance.step-budget' || ($run['transport'] ?? null) !== 'gateway'
            || ($run['step'] ?? null) !== $step || ! is_int($run['result']['budget_seconds'] ?? null)
            || $run['result']['budget_seconds'] <= 0 || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', $step)->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $step) !== $run['result']) {
            throw $this->unproven();
        }
    }

    private function provenScope(Operation $operation): void
    {
        $run = InstanceExecutionEvidence::run($operation, 'lifecycle.scope');
        $binary = $run['result']['binary'] ?? null;
        if (($run['name'] ?? null) !== 'tool.inspect-vp-scope' || ($run['transport'] ?? null) !== 'agent'
            || ! is_string($binary) || ! str_starts_with($binary, '/') || ! str_ends_with($binary, '/bin/vp')
            || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', 'lifecycle_scope')->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, 'lifecycle_scope') !== $run['result']) {
            throw $this->unproven();
        }
    }

    private function proven(Operation $operation, string $key, string $command): void
    {
        $run = InstanceExecutionEvidence::run($operation, $key);
        if (! is_array($run) || ($run['name'] ?? null) !== $command || ($run['transport'] ?? null) !== 'agent'
            || ($run['result']['exit_code'] ?? null) !== 0 || ($run['result']['timed_out'] ?? true) !== false
            || isset($run['result']['error_code'])
            || ! $operation->steps()->where('name', $key)->where('status', 'succeeded')->exists()
            || InstanceExecutionEvidence::step($operation, $key) !== $run['result']) {
            throw $this->unproven();
        }
    }

    /** @param array<string,mixed> $input
     * @return Descriptor
     */
    private function descriptor(string $key, string $step, string $transport, string $name, array $input, bool $resumable = true): array
    {
        return compact('key', 'step', 'transport', 'name', 'input', 'resumable') + ['required' => true];
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'A bound Project step result is required.', 409);
    }
}
