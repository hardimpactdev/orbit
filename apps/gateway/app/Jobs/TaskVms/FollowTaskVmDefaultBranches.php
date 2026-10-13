<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
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
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps workspace templates at their Project's default branch. The scheduler queues it every 5 minutes on the template
 * queue. On every configured host whose Node is active, it reads the current template of each Project: the newest one
 * that a claim on the host's current base image can take. For each Project with one, it fetches the default branch into
 * the Gateway's mirror once, and queues {@see BuildTaskVmWorkspaceTemplate} on each host whose template is at another
 * commit, unless a failed build there waits out its backoff. So every merge counts, by a task group, an auto-merge, a
 * human, or a push: the Gateway reads the branch itself. A Project without a current template gets its template from a
 * claim or the refresh after a promote. A host or a fetch that fails is logged, and the rest still runs.
 */
final class FollowTaskVmDefaultBranches implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onTaskVmQueue(TaskVmQueue::Templates);
    }

    public function handle(TaskVmSettings $settings, TaskVmWorkspaceTemplateVolumes $volumes, IncusHost $incus, IncusTaskVmWorkspaceTemplateBuilder $builder): void
    {
        $projects = Project::query()->orderBy('id')->get()
            ->filter(static fn (Project $project): bool => TaskVmWorkspaceTemplateLifecycle::unbuildable($project) === null)
            ->keyBy('id');

        /** @var array<int, array<int, TaskVmWorkspaceTemplate>> $current the current template of each Project, by Project and host Node id */
        $current = [];
        foreach ($settings->hosts as $host) {
            $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first();
            if (! $node instanceof Node) {
                continue;
            }
            try {
                $image = $incus->aliasTarget($host, $node, TaskVmHost::BaseImage, 60.0);
                $templates = $volumes->read($host, $node);
            } catch (Throwable $exception) {
                Log::error('Task VM workspace templates could not be read to follow the default branches.', ['host_node_id' => $node->id, 'error' => $exception->getMessage()]);

                continue;
            }
            foreach ($projects as $project) {
                $template = TaskVmWorkspaceTemplate::newest($templates, $project->id, $project->repository_url, $image);
                if ($template instanceof TaskVmWorkspaceTemplate) {
                    $current[$project->id][$node->id] = $template;
                }
            }
        }

        foreach ($current as $projectId => $templates) {
            $project = $projects[$projectId];
            try {
                $tip = $builder->defaultBranchTip($project);
            } catch (Throwable $exception) {
                Log::warning('Task VM workspace template could not follow its default branch.', ['project_id' => $project->id, 'error' => $exception->getMessage()]);

                continue;
            }
            foreach ($templates as $hostNodeId => $template) {
                if ($template->commit !== $tip && ! Cache::has(TaskVmWorkspaceTemplateLifecycle::backoffKey($hostNodeId, $project->id))) {
                    BuildTaskVmWorkspaceTemplate::dispatch($hostNodeId, $project->id);
                }
            }
        }
    }
}
