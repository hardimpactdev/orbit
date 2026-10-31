<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Classifies the step-list transport evidence, never Swamp's terminal result body. */
final readonly class InstanceLifecycleStepOutcome
{
    /** @return array{outcome:string,error_code:?string,failed_step:?string} */
    public function read(Operation $child): array
    {
        if ($child->kind !== 'instance.run_steps') {
            throw new ResourceOperationException('operation.child_unproven', 'Lifecycle list outcomes require a step-list child.', 409);
        }
        $unknown = ['outcome' => 'unconfirmed', 'error_code' => 'instance.lifecycle_unconfirmed', 'failed_step' => null];
        if ($child->status === OperationStatus::Cancelled) {
            return ['outcome' => 'cancelled', 'error_code' => 'operation.cancelled', 'failed_step' => null];
        }
        $phase = $child->input['list'] ?? null;
        $runs = $child->state['executor_runs'] ?? [];
        if (! in_array($phase, ['setup', 'teardown'], true) || ! is_array($runs)) {
            return $unknown;
        }
        if ($child->status === OperationStatus::Succeeded) {
            $run = $runs['lifecycle.receipt'] ?? null;
            if (is_array($run) && ($run['transport'] ?? null) === 'gateway' && ($run['name'] ?? null) === 'instance.step-list-receipt'
                && ($run['step'] ?? null) === 'lifecycle_receipt' && is_array($run['result'] ?? null)
                && ($run['result']['instance_id'] ?? null) === $child->target_id
                && ($run['result']['outcome'] ?? null) === 'succeeded' && ! isset($run['result']['error_code'])
                && $child->steps()->where('name', 'lifecycle_receipt')->where('status', 'succeeded')->exists()) {
                return ['outcome' => 'succeeded', 'error_code' => null, 'failed_step' => null];
            }

            return $unknown;
        }
        $failure = null;
        foreach ($runs as $key => $run) {
            if (! is_array($run) || (($run['started'] ?? false) === true && ! is_array($run['result'] ?? null))) {
                return $unknown;
            }
            $receipt = $run['result'] ?? null;
            if (! is_array($receipt)) {
                continue;
            }
            if (! isset($receipt['error_code']) && ($receipt['exit_code'] ?? 0) === 0 && ($receipt['timed_out'] ?? false) === false) {
                continue;
            }
            $step = $run['step'] ?? null;
            if (! is_string($step) || ! $child->steps()->where('name', $step)->where('status', 'failed')->exists()) {
                return $unknown;
            }
            if (($run['transport'] ?? null) === 'gateway' && ($run['name'] ?? null) === 'instance.step-budget'
                && ($receipt['error_code'] ?? null) === 'command.deadline_exceeded'
                && is_string($key) && str_starts_with($key, 'budget.'.$phase.'.') && $step === substr($key, 7).'.budget') {
                $current = ['outcome' => 'deadline', 'error_code' => 'command.deadline_exceeded', 'failed_step' => $step];
            } elseif (($run['transport'] ?? null) === 'agent' && ($run['name'] ?? null) === 'instance.run-lifecycle-step'
                && $step === $key && is_array($run['input'] ?? null) && is_string($run['input']['step'] ?? null) && $key === $phase.'.'.$run['input']['step']) {
                $outcome = match (true) {
                    ($receipt['error_code'] ?? null) === 'instance.lifecycle_busy', ($receipt['exit_code'] ?? null) === 75 => 'busy',
                    isset($receipt['error_code']) => 'unconfirmed',
                    in_array($receipt['exit_code'] ?? null, [126, 127], true) => 'missing',
                    ($receipt['timed_out'] ?? false) === true => is_int($receipt['budget_seconds'] ?? null)
                        && is_int($receipt['timeout_seconds'] ?? null) && $receipt['budget_seconds'] < $receipt['timeout_seconds'] ? 'deadline' : 'failed',
                    in_array($receipt['exit_code'] ?? null, [1, 124], true) => 'failed',
                    default => 'unconfirmed',
                };
                if ($outcome === 'unconfirmed') {
                    return $unknown;
                }
                $current = ['outcome' => $outcome, 'error_code' => match ($outcome) {
                    'busy' => 'instance.lifecycle_busy', 'missing' => 'instance.'.$phase.'_step_unavailable',
                    'deadline' => 'command.deadline_exceeded', default => 'instance.'.$phase.'_step_failed',
                }, 'failed_step' => $step];
            } else {
                return $unknown;
            }
            if ($failure === null || $current['outcome'] === 'busy') {
                $failure = $current;
            }
        }

        return $failure ?? $unknown;
    }
}
