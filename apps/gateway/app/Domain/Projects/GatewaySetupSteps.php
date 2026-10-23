<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use InvalidArgumentException;

/**
 * The Gateway-owned setup operations: a setup step whose command names one of them is not a shell command. The agent
 * runs the operation's own program, which ships with it, as the step. Instance setup and the task baseline setup list
 * run them the same way. Teardown rejects them.
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

    /** The agent program of an owned operation, for `instances.run-lifecycle-step`. */
    public static function program(string $operation): string
    {
        return match ($operation) {
            LifecycleStep::ImportCiCaches => 'import_ci_caches',
            default => throw new InvalidArgumentException("{$operation} is not a Gateway-owned setup operation."),
        };
    }

    /**
     * The shell script of an owned operation, the program the agent ships, for the task check runner, which still
     * runs its setup list as shell.
     */
    public static function script(string $operation): string
    {
        self::program($operation);
        $script = @file_get_contents(base_path('../agent/src/commands/ci_caches.sh'));

        return is_string($script) && $script !== ''
            ? $script
            : throw new ResourceOperationException('instance.ci_caches_unavailable', 'The CI cache installer is unavailable.', 503);
    }
}
