<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Bounded multi-pass restore loop for node-scoped Doctor convergence.
 */
final class DoctorRestoreConvergence
{
    public const int MAX_PASSES = 8;

    /**
     * @param  callable(): array{issues?: list<array<string, mixed>>}  $probe
     * @param  callable(list<array<string, mixed>>): list<array<string, mixed>>  $apply
     * @param  callable(array<string, mixed>): bool  $isRestorable
     * @return array{
     *     probe: array{issues?: list<array<string, mixed>>},
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
     * @param  list<array<string, mixed>>  $restorable
     * @return array{
     *     probe: array{issues?: list<array<string, mixed>>},
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

            $passActions = ($callbacks->apply)($this->allIssues($state->probe));
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
     * @param  array{issues?: list<array<string, mixed>>}  $probe
     * @param  callable(array<string, mixed>): bool  $isRestorable
     * @return list<array<string, mixed>>
     */
    private function restorableIssues(array $probe, callable $isRestorable): array
    {
        return array_values(array_filter($this->allIssues($probe), $isRestorable));
    }

    /**
     * @param  array{issues?: list<array<string, mixed>>}  $probe
     * @return list<array<string, mixed>>
     */
    private function allIssues(array $probe): array
    {
        $issues = $probe['issues'] ?? [];

        return array_values(array_filter($issues, is_array(...)));
    }
}
