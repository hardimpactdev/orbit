<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * The task account is optional during rollout, but a configured account never falls back to SSH's user.
 * A task VM has no task account: its managed user `orbit` runs the agents and the checks.
 *
 * Filter-capable Gateway Git on a development checkout runs as the worker on every app-dev Node that does not opt
 * out, and on every checkout ever shared with the worker. That Node must have the account.
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
     * Configuration decides, never Process state: every Node with an app-dev role uses the worker unless its
     * settings opt out with `tasks.worker = none`. A task VM Node never has a worker.
     */
    public static function forNode(Node $node): ?string
    {
        if (self::configured() === null || ! self::usesWorker($node)) {
            return null;
        }

        return self::name();
    }

    /**
     * A checkout that was ever shared with the worker keeps the worker, wherever it is now. A missing account then
     * fails the caller instead of running a planted filter as the managed user.
     */
    public static function forCheckout(Instance $instance): ?string
    {
        if (self::configured() === null || $instance->task_sandbox_id !== null) {
            return null;
        }
        $node = $instance->node;
        if ($node instanceof Node && TaskVmPlacement::forNode($node) instanceof TaskVm) {
            return null;
        }
        if ($instance->worker_shared) {
            return self::name();
        }

        return $node instanceof Node ? self::forNode($node) : null;
    }

    public static function optedOut(Node $node): bool
    {
        return data_get($node->settings, 'tasks.worker') === 'none';
    }

    private static function usesWorker(Node $node): bool
    {
        return ! self::optedOut($node)
            && $node->roles()->where('role', RoleName::AppDev)->exists()
            && ! (TaskVmPlacement::forNode($node) instanceof TaskVm);
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
