<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The model route of task VMs ([Credentials in task VMs](/reference/tasks#credentials-in-task-vms)): the guard origin Pi calls and
 * the one fixed placeholder key every task VM holds. The extension that runs the model router binds it. The key changes only on an incident.
 */
interface TaskVmModelRoute
{
    /** Refuses while the route has no guard, for example before the ai-router setup (`ai_router.disabled`, 409). */
    public function assertReady(): void;

    /** The guard origin that Pi on a task VM calls, such as `http://10.44.0.3:8318`. */
    public function origin(): string;

    /** The fixed placeholder key; only the guard accepts it. */
    public function key(): string;
}
