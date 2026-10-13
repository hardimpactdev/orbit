<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmWorkspaceTemplateBuilder;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplate;
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
 * Brings the workspace template of one Project on one task VM host to the Project's default branch. A claim queues it
 * when the Project has no template for the VM's base image, the template refresh queues it for each recently active
 * `vm` Project on every host, and {@see FollowTaskVmDefaultBranches} queues it after a merge. It runs on the template
 * queue, whose own worker takes one job at a time, so a build never delays task VM jobs.
 *
 * A host that has a current template of the Project, ready on the host's current base image, from the Project's
 * repository and owned by the image's user, gets an update of that template to the default branch tip, or nothing at
 * the tip ({@see IncusTaskVmWorkspaceTemplateBuilder::update()}). Without one, or after
 * {@see TaskVmWorkspaceTemplateLifecycle::MaxUpdates} updates, it builds a new template from scratch. The job is unique
 * for its host and Project and reads the tip when it runs, so a burst of merges becomes one update to the newest commit.
 *
 * While another template build runs on the host, the job waits a minute and tries again. A Project without a valid
 * default branch or repository is logged and skipped, without a retry. A failed update makes the next try a full build.
 * A failed full build logs an error, and claims and merges ask for no new build of that Project on that host for
 * 6 hours; their workspaces start from a fresh clone or from the older template.
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
        $this->onTaskVmQueue(TaskVmQueue::Templates);
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
        $current = null;
        try {
            $current = TaskVmWorkspaceTemplate::newest($volumes->read($host, $node), $project->id, $project->repository_url, $incus->aliasTarget($host, $node, TaskVmHost::BaseImage, 60.0));
        } catch (Throwable) {
            // The build reads the host again and fails loudly when it cannot.
        }
        if ($current instanceof TaskVmWorkspaceTemplate && $current->updates < TaskVmWorkspaceTemplateLifecycle::MaxUpdates) {
            $this->update($builder, $volumes, $host, $node, $project, $current);

            return;
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

    /**
     * Updates the current template to the default branch tip. At the template's own commit nothing happens. A failed
     * fetch only warns: the next check tries again. A failed update marks the template for a full build and puts the job
     * back at once, so its next try builds from scratch. Claims keep taking the current template meanwhile.
     */
    private function update(IncusTaskVmWorkspaceTemplateBuilder $builder, TaskVmWorkspaceTemplateVolumes $volumes, TaskVmHost $host, Node $node, Project $project, TaskVmWorkspaceTemplate $current): void
    {
        $context = ['host_node_id' => $node->id, 'project_id' => $project->id, 'template' => $current->name];
        try {
            $commit = $builder->defaultBranchTip($project);
        } catch (Throwable $exception) {
            Log::warning('Task VM workspace template could not follow its default branch.', [...$context, 'error' => TaskVmException::messageOf($exception)]);

            return;
        }
        if ($commit === $current->commit) {
            Log::info('Task VM workspace template is at the default branch; no update.', [...$context, 'commit' => $commit]);

            return;
        }
        try {
            $volume = $builder->update($host, $node, $project, $current, $commit, owner: $this->owner());
        } catch (TaskVmException $exception) {
            if ($exception->errorCode === 'task_vm.workspace_template_running') {
                $this->release(60);

                return;
            }
            Log::warning('Task VM workspace template update failed; the next build is a full one.', [...$context, 'error_code' => $exception->errorCode, 'error' => $exception->getMessage()]);
            try {
                $volumes->setUpdates($host, $node, $current->name, TaskVmWorkspaceTemplateLifecycle::MaxUpdates);
            } catch (Throwable $marking) {
                $this->recordFailure($marking);
                $this->fail($marking);

                return;
            }
            $this->release(0);

            return;
        }
        Cache::forget(TaskVmWorkspaceTemplateLifecycle::backoffKey($this->hostNodeId, $this->projectId));
        Log::info('Task VM workspace template updated.', [...$context, 'volume' => $volume, 'commit' => $commit, 'updates' => $current->updates + 1]);
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
