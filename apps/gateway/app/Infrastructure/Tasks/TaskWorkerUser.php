<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * The task account is optional during rollout, but a configured account never falls back to SSH's user.
 * A task VM has no task account: its managed user `orbit` runs the agents and the checks.
 *
 * Only a Node that hosts shared task work runs filter-capable Git as the worker, because only there can a task
 * agent write a checkout. That Node must have the account. Every other Node runs that Git as its managed user.
 */
final readonly class TaskWorkerUser
{
    public static function name(?Instance $workspace = null): ?string
    {
        if ($workspace?->task_sandbox_id !== null) {
            return null;
        }
        $worker = self::configured();
        if ($worker === null) {
            return null;
        }
        if ($workspace?->node instanceof Node && TaskVmPlacement::forNode($workspace->node) instanceof TaskVm) {
            return null;
        }
        if (! is_string($worker) || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $worker) !== 1 || $worker === 'root') {
            throw new RuntimeConvergenceException(
                step: 'task-worker-user',
                errorCode: 'tasks.check_failed',
                message: 'The task worker user is invalid.',
            );
        }

        return $worker;
    }

    /**
     * The worker for Gateway Git that can start checkout filters on a Node, or null to use the Node's managed user.
     * A Node hosts shared task work when it is an active app-dev Node that an agent driver allows (today: a running
     * `pi-server` Process), or when it still holds a task workspace. A task VM Node never has a worker.
     */
    public static function forNode(Node $node): ?string
    {
        if (self::configured() === null || TaskVmPlacement::forNode($node) instanceof TaskVm || ! self::hostsTaskWork($node)) {
            return null;
        }

        return self::name();
    }

    private static function hostsTaskWork(Node $node): bool
    {
        if (Instance::query()->where('node_id', $node->id)->whereHas('tasks')->exists()) {
            return true;
        }

        return $node->roles()
            ->where('role', RoleName::AppDev)
            ->where('status', LifecycleStatus::Active)
            ->exists()
            && app(AgentDriverRegistry::class)->anyAllows($node);
    }

    private static function configured(): mixed
    {
        $worker = config('orbit.tasks.worker_user');

        return $worker === '' ? null : $worker;
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    public static function arguments(array $arguments, ?Instance $workspace = null): array
    {
        $worker = self::name($workspace);

        if ($worker === null) {
            return $arguments;
        }

        return ['bash', '-ceu', <<<'BASH'
            worker=$1
            shift
            worker_uid=$(id -u "$worker") || exit 126
            test "$worker_uid" != 0 && test "$worker_uid" != "$(id -u)" || exit 126
            exec sudo -n -u "$worker" -H -- "$@"
            BASH, '--', $worker, ...$arguments];
    }
}
