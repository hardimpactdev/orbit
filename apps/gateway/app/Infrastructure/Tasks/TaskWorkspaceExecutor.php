<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/** Routes task operations by persisted workspace ownership; never by available capacity. */
final readonly class TaskWorkspaceExecutor
{
    public function __construct(private DevelopmentSshExecutor $shared) {}

    public function execute(Instance $workspace, RemoteCommand $command, string $step, string $errorCode, ?float $commandTimeout = null, string $failureLabel = 'Task workspace', bool $mutates = false): CommandResult
    {
        try {
            TaskVmWorkspaceGuard::assertOwnedWorkspace($workspace);
        } catch (TaskVmException $exception) {
            throw new RuntimeConvergenceException($step, $errorCode, $exception->getMessage(), previous: $exception);
        }

        $leases = app(TaskWorkspaceLease::class);
        $lease = $mutates ? $leases->remoteStarted($workspace) : null;
        try {
            $result = $this->shared->execute($workspace->node, $command, $step, $errorCode, $commandTimeout, $failureLabel);
        } catch (RuntimeConvergenceException $exception) {
            // A remote exit proves termination. A transport loss or local timeout does not.
            if ($exception->result !== null && ! in_array($exception->result->exitCode, [124, 137, 143, 255], true)) {
                $leases->remoteFinished($lease);
            }
            throw $exception;
        }
        $leases->remoteFinished($lease);

        return $result;
    }
}
