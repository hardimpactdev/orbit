<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmWorkspaceTemplateBuilder;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the workspace template of one Project on one task VM host. The nightly refresh queues it for every `vm`
 * Project on every host, and a claim queues it when the Project has no template on the VM's host. While another
 * template build runs on the host, the job waits a minute and tries again. A failed build logs an error, and claims
 * ask for no new build of that Project on that host for 6 hours; their workspaces start from a fresh clone.
 */
final class BuildTaskVmWorkspaceTemplate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** The build's own budget plus its cleanup, below the queue's `retry_after` of 1800 seconds. */
    public int $timeout = 1700;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public int $hostNodeId, public int $projectId)
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function uniqueId(): string
    {
        return "{$this->hostNodeId}:{$this->projectId}";
    }

    public function handle(TaskVmSettings $settings, IncusTaskVmWorkspaceTemplateBuilder $builder): void
    {
        $project = Project::query()->find($this->projectId);
        $node = Node::query()->whereKey($this->hostNodeId)->where('status', LifecycleStatus::Active)->first();
        if (! $project instanceof Project || ! $node instanceof Node || ! TaskVmWorkspaceTemplates::serves($project)) {
            return;
        }

        try {
            $volume = $builder->build($settings->host($node->id), $node, $project, owner: $this->owner());
        } catch (TaskVmException $exception) {
            if ($exception->errorCode === 'task_vm.workspace_template_running') {
                $this->release(60);

                return;
            }
            $this->recordFailure($exception);
            $this->fail($exception);

            return;
        }
        Cache::forget(TaskVmWorkspaceTemplates::backoffKey($this->hostNodeId, $this->projectId));
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
        Cache::put(TaskVmWorkspaceTemplates::backoffKey($this->hostNodeId, $this->projectId), now()->getTimestamp(), TaskVmWorkspaceTemplates::FailureBackoffSeconds);
        Log::error('Task VM workspace template build failed; workspaces of the Project start from a fresh clone.', [
            'host_node_id' => $this->hostNodeId,
            'project_id' => $this->projectId,
            'error_code' => TaskVmException::codeOf($exception),
            'error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }

    private function owner(): string
    {
        return "job-{$this->hostNodeId}-{$this->projectId}";
    }
}
