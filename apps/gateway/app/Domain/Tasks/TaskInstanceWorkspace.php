<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * The container Instance workspace of a task group (docs/reference/task-instance-workspace.md#check-phases). The group's
 * Instance has a recorded container runtime on its own Node and names the group as its workspace owner. Swamp reaches the
 * container through that Node; every guest command runs as `agent` in the fixed root. A group without one keeps its task
 * VM path. A task copy (TaskCopyStart) creates this ownership.
 */
final readonly class TaskInstanceWorkspace
{
    /** The guest checkout of every container Instance; the admitted path is never a Node path. */
    public const string Root = '/srv/workspace';

    private function __construct(public Instance $instance, public InstanceRuntime $runtime) {}

    public static function forGroup(Task $group): ?self
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id) {
            return null;
        }
        $runtime = $instance->runtime;

        return $runtime instanceof InstanceRuntime && $runtime->node_id === $instance->node_id ? new self($instance, $runtime) : null;
    }

    /**
     * The recorded guest target and a new scratch ID for one admitted check.
     *
     * @return array{runtime: array{project: string, name: string}, branch: string, snapshot_id: string}
     */
    public function intent(): array
    {
        if ($this->runtime->power_state !== InstanceRuntimePowerState::Running) {
            throw new ResourceOperationException('tasks.instance_not_running', 'The container of the task workspace is not running.', 409);
        }
        $branch = $this->instance->branch;
        if (! is_string($branch) || $branch === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The task workspace Instance has no recorded branch.', 409);
        }

        return ['runtime' => ['project' => $this->runtime->incus_project, 'name' => $this->runtime->container_name], 'branch' => $branch, 'snapshot_id' => (string) Str::uuid()];
    }

    /**
     * Refuses what the container check cannot run yet, before a check row exists: the hosted CI push needs the Node-side
     * push of publication, and the CI cache setup program reads the Gateway mirror from a task VM. There is no task VM fallback.
     *
     * @param  list<array{name: string, command: string, timeout_seconds: int}>  $setup
     */
    public static function refuseUnavailable(?string $hostedCheck, array $setup): void
    {
        if ($hostedCheck !== null) {
            throw new ResourceOperationException('tasks.instance_hosted_check_unavailable', 'A container task workspace cannot run a hosted handoff check yet.', 409);
        }
        if (in_array('@orbit/ci-caches', array_column($setup, 'command'), true)) {
            throw new ResourceOperationException('tasks.instance_setup_unavailable', 'A container task workspace cannot run the @orbit/ci-caches setup step.', 409);
        }
    }
}
