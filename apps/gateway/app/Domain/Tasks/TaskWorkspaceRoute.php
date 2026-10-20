<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

/**
 * Finishes the Route of a task workspace that {@see InstanceProvisioning} returned before its Route was active. A
 * task VM workspace starts its baseline check first, because the check needs the checkout, its `.env`, and the PHP
 * packages, not the Route. The scheduler finishes the Route after the check started, and again before any work that
 * uses it: the implementer and the final review.
 */
#[Bind(TaskWorkspaceProvisioner::class)]
interface TaskWorkspaceRoute
{
    /** Whether the group's workspace is routed and its Route is not active yet. */
    public function pending(Task $group): bool;

    /** Projects the pending Route and activates the workspace. Null on success or when nothing is pending. */
    public function complete(Task $group): ?InstanceProvisionFailure;
}
