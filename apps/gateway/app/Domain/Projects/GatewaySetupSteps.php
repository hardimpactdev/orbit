<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

/**
 * The Gateway-owned setup operations: a setup step whose command names one of them is not a shell command. The
 * Gateway renders the operation's command right before the step runs, within the step's timeout. Instance setup, the
 * task baseline setup list, and a task VM workspace template build run them the same way. Teardown rejects them.
 */
final readonly class GatewaySetupSteps
{
    public function __construct(private TiaBaselineSetup $tia, private CiCacheSetup $ci) {}

    public static function owns(string $command): bool
    {
        return $command === LifecycleStep::RestoreTiaBaseline || $command === LifecycleStep::ImportCiCaches;
    }

    /** Whether an error is one of the operations' own errors, which name their step. */
    public static function failure(ResourceOperationException $exception): bool
    {
        return str_starts_with($exception->errorCode, 'instance.tia_baseline_') || str_starts_with($exception->errorCode, 'instance.ci_caches_');
    }

    /** The shell command of an owned operation. */
    public function command(Project $project, string $operation, float $timeout): string
    {
        return $operation === LifecycleStep::ImportCiCaches
            ? $this->ci->command($project, $timeout)
            : $this->tia->command($project, $timeout);
    }
}
