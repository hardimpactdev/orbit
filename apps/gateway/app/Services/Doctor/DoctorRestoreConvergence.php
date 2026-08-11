<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorRestoreProbe;

/**
 * Bounded multi-pass restore loop for node-scoped Doctor convergence.
 */
final class DoctorRestoreConvergence
{
    public const int MAX_PASSES = 8;

    /**
     * @param  callable(): DoctorRestoreProbe  $probe
     * @param  callable(list<DoctorIssue>): list<array<string, mixed>>  $apply
     * @param  callable(DoctorIssue): bool  $isRestorable
     * @return array{
     *     probe: DoctorRestoreProbe,
     *     actions: list<array<string, mixed>>,
     *     passes: int,
     *     stop_reason: 'converged'|'no_progress'|'max_passes'|'no_restorable'
     * }
     */
    public function run(
        callable $probe,
        callable $apply,
        callable $isRestorable,
        int $maxPasses = self::MAX_PASSES,
    ): array {
        $state = new DoctorRestorePassState(
            probe: $probe(),
            actions: [],
            passes: 0,
            previousSignature: null,
        );
        $restorable = $this->restorableIssues($state->probe, $isRestorable);

        if ($restorable === []) {
            return $state->toResult('no_restorable');
        }

        $callbacks = new DoctorRestoreCallbacks($probe, $apply, $isRestorable);

        return $this->runPasses($state, $restorable, $callbacks, $maxPasses);
    }

    /**
     * @param  list<DoctorIssue>  $restorable
     * @return array{
     *     probe: DoctorRestoreProbe,
     *     actions: list<array<string, mixed>>,
     *     passes: int,
     *     stop_reason: 'converged'|'no_progress'|'max_passes'|'no_restorable'
     * }
     */
    private function runPasses(
        DoctorRestorePassState $state,
        array $restorable,
        DoctorRestoreCallbacks $callbacks,
        int $maxPasses,
    ): array {
        while (true) {
            $signature = DoctorRestoreIssueSignature::fromIssues($restorable);
            $preStop = $state->stopReasonBeforeApply($signature, $maxPasses);

            if ($preStop !== null) {
                return $state->toResult($preStop);
            }

            $passActions = ($callbacks->apply)($state->probe->issues);
            $state = $state->afterApply($passActions, $signature);
            $state = $state->withProbe(($callbacks->probe)());
            $restorable = $this->restorableIssues($state->probe, $callbacks->isRestorable);

            if ($restorable === []) {
                return $state->toResult('converged');
            }

            if (! DoctorRestorePassState::actionsMadeProgress($passActions)) {
                return $state->toResult('no_progress');
            }
        }
    }

    /**
     * @param  callable(DoctorIssue): bool  $isRestorable
     * @return list<DoctorIssue>
     */
    private function restorableIssues(DoctorRestoreProbe $probe, callable $isRestorable): array
    {
        return array_values(array_filter($probe->issues, $isRestorable));
    }
}
