<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
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

        // This refusal is known to precede every SSH dispatch.
        if ($mutates && (! is_string($workspace->node->wireguard_ip) || $workspace->node->wireguard_ip === '')) {
            throw new RuntimeConvergenceException($step, 'app-dev.wireguard_ip_missing', 'The workspace Node has no WireGuard address.');
        }

        return $this->shared->execute($workspace->node, $command, $step, $errorCode, $commandTimeout, $failureLabel);
    }
}
