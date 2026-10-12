<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/** Routes task operations by persisted workspace ownership; never by available capacity. */
final readonly class TaskWorkspaceExecutor
{
    public function __construct(private DevelopmentSshExecutor $shared) {}

    public function execute(Instance $workspace, RemoteCommand $command, string $step, string $errorCode, ?float $commandTimeout = null, string $failureLabel = 'Task workspace'): CommandResult
    {
        try {
            TaskVmPlacement::assertOwnedWorkspace($workspace);
        } catch (TaskVmException $exception) {
            throw new RuntimeConvergenceException($step, $errorCode, $exception->getMessage(), previous: $exception);
        }

        return $this->shared->execute($workspace->node, $command, $step, $errorCode, $commandTimeout, $failureLabel);
    }
}
