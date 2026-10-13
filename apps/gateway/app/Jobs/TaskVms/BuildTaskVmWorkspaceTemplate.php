<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmWorkspaceTemplateBuilder;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplateLifecycle;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplateVolumes;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the workspace template of one Project on one task VM host. A claim queues it when the Project has no
 * template for the VM's base image, and the template refresh queues it for each recently active `vm` Project on
 * every host. It runs on the `builds` queue, whose one process runs one build at a time, so a build never delays
 * task VM jobs. A host that already has a ready template of the Project on its current base image, from the Project's
 * repository and owned by the image's user, gets no build. While another template build runs on the host, the job waits a minute and tries again. A Project without
 * a valid default branch or repository is logged and skipped, without a retry. A failed build logs an error, and
 * claims ask for no new build of that Project on that host for 6 hours; their workspaces start from a fresh clone.
 */
final class BuildTaskVmWorkspaceTemplate implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 10;

    /** The build's own budget plus its cleanup, below the queue's `retry_after` of 1800 seconds. */
    public int $timeout = 1700;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public int $hostNodeId, public int $projectId)
    {
        $this->onTaskVmQueue(GatewayQueue::Builds);
    }

    public function uniqueId(): string
    {
        return "{$this->hostNodeId}:{$this->projectId}";
    }

    public function handle(TaskVmSettings $settings, IncusTaskVmWorkspaceTemplateBuilder $builder, TaskVmWorkspaceTemplateVolumes $volumes, IncusHost $incus): void
    {
        $project = Project::query()->find($this->projectId);
        $node = Node::query()->whereKey($this->hostNodeId)->where('status', LifecycleStatus::Active)->first();
        if (! $project instanceof Project || ! $node instanceof Node) {
            return;
        }
        if (! TaskVmWorkspaceTemplateLifecycle::buildable($project, $node->id)) {
            return;
        }

        $host = $settings->host($node->id);
        try {
            if (TaskVmWorkspaceTemplateLifecycle::isCurrent($volumes->read($host, $node), $project, $incus->aliasTarget($host, $node, TaskVmHost::BaseImage, 60.0))) {
                Log::info('Task VM workspace template is current; no build.', ['host_node_id' => $node->id, 'project_id' => $project->id]);

                return;
            }
        } catch (Throwable) {
            // The build reads the host again and fails loudly when it cannot.
        }
        try {
            $volume = $builder->build($host, $node, $project, owner: $this->owner());
        } catch (TaskVmException $exception) {
            if ($exception->errorCode === 'task_vm.workspace_template_running') {
                $this->release(60);

                return;
            }
            $this->recordFailure($exception);
            $this->fail($exception);

            return;
        }
        Cache::forget(TaskVmWorkspaceTemplateLifecycle::backoffKey($this->hostNodeId, $this->projectId));
        Log::info('Task VM workspace template built.', ['host_node_id' => $this->hostNodeId, 'project_id' => $this->projectId, 'volume' => $volume]);
    }

    /** After a timeout the worker stops the job in the middle of a build, so this deletes its builder and frees the host. */
    public function failed(?Throwable $exception): void
    {
        $node = Node::query()->find($this->hostNodeId);
        if ($node instanceof Node) {
            try {
                app(IncusTaskVmWorkspaceTemplateBuilder::class)->abandon(app(TaskVmSettings::class)->host($node->id), $node, $this->owner());
            } catch (Throwable) {
                // The next build on the host sweeps what remains.
            }
        }
        if ($exception instanceof Throwable && ! $exception instanceof TaskVmException) {
            $this->recordFailure($exception);
        }
    }

    private function recordFailure(Throwable $exception): void
    {
        Cache::put(TaskVmWorkspaceTemplateLifecycle::backoffKey($this->hostNodeId, $this->projectId), now()->getTimestamp(), TaskVmWorkspaceTemplateLifecycle::FailureBackoffSeconds);
        Log::error('Task VM workspace template build failed; workspaces of the Project start from a fresh clone.', [
            'host_node_id' => $this->hostNodeId,
            'project_id' => $this->projectId,
            'error_code' => TaskVmException::codeOf($exception),
            'error' => TaskVmException::messageOf($exception),
        ]);
    }

    private function owner(): string
    {
        return "job-{$this->hostNodeId}-{$this->projectId}";
    }
}
