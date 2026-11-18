<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * The container Instance workspace of a task group (docs/reference/task-instance-workspace.md#check-phases). The group's
 * Instance has a recorded container runtime on its own Node and names the group as its workspace owner. Swamp reaches the
 * container through that Node; every guest command runs as `agent` in the fixed root. A group without one keeps its task
 * VM path. A task copy (TaskStart) creates this ownership.
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
        $this->requireReady();
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
     * Refuses a turn, a check, a publish and a proof of a task copy that is not ready (docs/reference/tasks.md#a-failed-completion). A
     * copy whose completion failed (TaskLifecycle::completionFailure) answers `tasks.copy_completion_failed`: the completion did not
     * start its Processes or a nested copy's inner fleet, and its retry may run at the same time. Any other copy that is not `active`
     * yet, because its create, its completion or a retry still runs, answers `tasks.copy_not_ready`.
     */
    public function requireReady(): void
    {
        $failure = TaskLifecycle::completionFailure($this->instance);
        if ($failure !== null) {
            throw new ResourceOperationException('tasks.copy_completion_failed', "The completion of the task copy failed. Retry it with `{$failure['retry']}`.", 409, details: $failure);
        }
        if ($this->instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('tasks.copy_not_ready', 'The task copy is not active yet.', 409);
        }
    }

    /**
     * What a publication from a task copy adds to its intent (docs/reference/tasks.md#task-copy): the recorded guest target, the copy's
     * start commit and the path of the `.env` Orbit wrote, relative to the checkout. No commit from the start on may hold that file.
     *
     * @return array{runtime: array{project: string, name: string}, branch: string, snapshot_id: string, start: string, environment_path: string}
     */
    public function publication(): array
    {
        // Readiness first: a copy whose create still runs has no start commit yet, and it is only not ready.
        $this->requireReady();
        $start = $this->start();
        $environment = InstanceContainer::environmentFile($this->instance);
        if (! str_starts_with($environment, self::Root.'/')) {
            throw new ResourceOperationException('tasks.no_workspace', 'The task copy has no recorded start commit.', 409);
        }

        return [...$this->intent(), 'start' => $start, 'environment_path' => substr($environment, strlen(self::Root) + 1)];
    }

    /** The copy's recorded start commit, the seed's commit that its branch began at. */
    public function start(): string
    {
        $start = $this->instance->starting_commit;
        if (! is_string($start) || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $start) !== 1) {
            throw new ResourceOperationException('tasks.no_workspace', 'The task copy has no recorded start commit.', 409);
        }

        return $start;
    }

    /**
     * Refuses what the container check cannot run yet, before a check row exists: the hosted CI push needs the Node-side
     * push of publication. There is no task VM fallback.
     */
    public static function refuseUnavailable(?string $hostedCheck): void
    {
        if ($hostedCheck !== null) {
            throw new ResourceOperationException('tasks.instance_hosted_check_unavailable', 'A container task workspace cannot run a hosted handoff check yet.', 409);
        }
    }
}
