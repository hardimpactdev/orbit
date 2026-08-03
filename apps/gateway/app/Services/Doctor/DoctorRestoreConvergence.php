<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Bounded multi-pass restore loop for node-scoped Doctor convergence.
 *
 * Each pass: apply supported genuine-drift restorers, re-probe the same fence,
 * and continue while new restorable findings appear. Stops on clean, no-progress,
 * or max passes without inventing repairs for non-genuine dispositions.
 */
final class DoctorRestoreConvergence
{
    public const MAX_PASSES = 8;

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
        $allActions = [];
        $previousSignature = null;
        $passes = 0;
        $probeResult = $probe();
        $issues = $this->issues($probeResult);
        $restorable = $this->filterRestorable($issues, $isRestorable);

        if ($restorable === []) {
            return [
                'probe' => $probeResult,
                'actions' => [],
                'passes' => 0,
                'stop_reason' => 'no_restorable',
            ];
        }

        while (true) {
            $signature = $this->signature($restorable);

            if ($signature === $previousSignature) {
                return [
                    'probe' => $probeResult,
                    'actions' => $allActions,
                    'passes' => $passes,
                    'stop_reason' => 'no_progress',
                ];
            }

            if ($passes >= $maxPasses) {
                return [
                    'probe' => $probeResult,
                    'actions' => $allActions,
                    'passes' => $passes,
                    'stop_reason' => 'max_passes',
                ];
            }

            $passActions = $apply($issues);
            $allActions = [...$allActions, ...$passActions];
            $previousSignature = $signature;
            $passes++;

            $probeResult = $probe();
            $issues = $this->issues($probeResult);
            $restorable = $this->filterRestorable($issues, $isRestorable);

            if ($restorable === []) {
                return [
                    'probe' => $probeResult,
                    'actions' => $allActions,
                    'passes' => $passes,
                    'stop_reason' => 'converged',
                ];
            }

            // A pass that applied no successful mutation cannot unlock dependents.
            // Stop before retrying the same genuine-drift set forever.
            if (! $this->passMadeProgress($passActions)) {
                return [
                    'probe' => $probeResult,
                    'actions' => $allActions,
                    'passes' => $passes,
                    'stop_reason' => 'no_progress',
                ];
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function passMadeProgress(array $actions): bool
    {
        foreach ($actions as $action) {
            if (in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{issues?: list<array<string, mixed>>}  $probe
     * @return list<array<string, mixed>>
     */
    private function issues(array $probe): array
    {
        $issues = $probe['issues'] ?? [];

        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter($issues, is_array(...)));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  callable(array<string, mixed>): bool  $isRestorable
     * @return list<array<string, mixed>>
     */
    private function filterRestorable(array $issues, callable $isRestorable): array
    {
        return array_values(array_filter($issues, $isRestorable));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function signature(array $issues): string
    {
        $parts = array_map(
            static function (array $issue): string {
                $family = is_string($issue['family'] ?? null) ? $issue['family'] : '';
                $code = is_string($issue['code'] ?? null)
                    ? $issue['code']
                    : (is_string($issue['key'] ?? null) ? $issue['key'] : '');
                $key = is_string($issue['key'] ?? null) ? $issue['key'] : '';
                $node = is_string($issue['node'] ?? null) ? $issue['node'] : '';
                $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];
                $detailToken = json_encode($detail, JSON_THROW_ON_ERROR);

                return "{$family}|{$code}|{$key}|{$node}|{$detailToken}";
            },
            $issues,
        );
        sort($parts);

        return implode("\n", $parts);
    }
}
