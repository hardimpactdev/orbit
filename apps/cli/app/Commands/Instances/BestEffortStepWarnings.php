<?php

declare(strict_types=1);

namespace App\Commands\Instances;

/**
 * The warning lines for the best-effort development deploy steps of a deployment that did not exit 0. A best-effort step that fails
 * does not fail the deployment, so these are warnings (docs/reference/deployments.md#development-deploy-steps).
 */
final class BestEffortStepWarnings
{
    /** @return list<string> */
    public static function of(mixed $steps): array
    {
        $lines = [];

        foreach (is_array($steps) ? $steps : [] as $step) {
            if (! is_array($step) || ! is_string($step['name'] ?? null) || ($step['exit_code'] ?? null) === 0) {
                continue;
            }

            $code = $step['exit_code'] ?? null;
            $lines[] = is_int($code)
                ? "best-effort step {$step['name']} exited {$code}"
                : "best-effort step {$step['name']} printed no exit code";
        }

        return $lines;
    }
}
