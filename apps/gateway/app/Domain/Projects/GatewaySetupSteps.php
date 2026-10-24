<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use InvalidArgumentException;

/**
 * The Gateway-owned setup operations: a setup step whose command names one of them is not a shell command. The agent
 * runs the operation natively for Instance setup. Task baseline setup uses the excluded task-workspace helper.
 * Teardown rejects these operations.
 */
final class GatewaySetupSteps
{
    public static function owns(string $command): bool
    {
        return $command === LifecycleStep::ImportCiCaches;
    }

    /** Whether an error is one of the operations' own errors, which name their step. */
    public static function failure(ResourceOperationException $exception): bool
    {
        return str_starts_with($exception->errorCode, 'instance.ci_caches_');
    }

    /** The native operation name for `instances.run-lifecycle-step`. */
    public static function program(string $operation): string
    {
        return match ($operation) {
            LifecycleStep::ImportCiCaches => 'import_ci_caches',
            default => throw new InvalidArgumentException("{$operation} is not a Gateway-owned setup operation."),
        };
    }

    /**
     * The task workspace script of an owned operation for the task check runner, which still
     * runs its setup list as shell.
     */
    public static function script(string $operation): string
    {
        self::program($operation);
        $script = @file_get_contents(resource_path('tasks/ci-caches.sh'));

        return is_string($script) && $script !== ''
            ? $script
            : throw new ResourceOperationException('instance.ci_caches_unavailable', 'The CI cache installer is unavailable.', 503);
    }
}
