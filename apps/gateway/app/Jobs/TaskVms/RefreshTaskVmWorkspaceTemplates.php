<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplateLifecycle;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplateVolumes;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The template refresh. Whatever builds or syncs a base image queues it once when it is done with every host: the
 * build and sync commands and the nightly base image refresh. So templates follow the image. It runs on the `builds`
 * queue, so it never delays task VM jobs. On every configured host whose Node is active, it deletes each ready template
 * that no claim can take any more: templates of Projects that no longer run task VMs, from an earlier repository, or
 * built on another base image than the host's current one, and all but the newest template of each Project. Then it queues one build for each Project that had a group
 * created within {@see TaskVmWorkspaceTemplateLifecycle::ActiveDays} days and has no current template on the host. Other
 * Projects get their template at their next claim. A Project that cannot have a template is logged and skipped, and
 * its templates are deleted. A host that fails is logged, and the other hosts still run.
 *
 * It is unique until it starts, so a build or sync that ends during a running refresh queues one more, which sees the
 * new alias. That one skips every template that is already current, so it builds only for the hosts whose alias moved.
 */
final class RefreshTaskVmWorkspaceTemplates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onTaskVmQueue(GatewayQueue::Builds);
    }

    public function handle(TaskVmSettings $settings, TaskVmWorkspaceTemplateVolumes $volumes, TaskVmWorkspaceTemplateLifecycle $lifecycle, IncusHost $incus): void
    {
        $projects = Project::query()->orderBy('id')->get();
        $buildable = $projects->filter(static fn (Project $project): bool => TaskVmWorkspaceTemplateLifecycle::buildable($project))->values();
        $repositories = [];
        foreach ($buildable as $project) {
            $repositories[$project->id] = $project->repository_url;
        }
        $active = Task::topLevel()
            ->whereIn('project_id', array_keys($repositories))
            ->where('created_at', '>=', now()->subDays(TaskVmWorkspaceTemplateLifecycle::ActiveDays))
            ->distinct()
            ->pluck('project_id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        foreach ($settings->hosts as $host) {
            $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first();
            if (! $node instanceof Node) {
                continue;
            }
            $image = null;
            $templates = [];
            try {
                $image = $incus->aliasTarget($host, $node, TaskVmHost::BaseImage, 60.0);
                $templates = $volumes->read($host, $node);
                $deleted = $lifecycle->pruneStale($host, $node, $repositories, $image, $templates);
                if ($deleted !== []) {
                    Log::info('Deleted the workspace templates that no claim can take.', ['host_node_id' => $node->id, 'image' => $image, 'volumes' => $deleted]);
                }
            } catch (Throwable $exception) {
                Log::error('The workspace template prune failed.', ['host_node_id' => $node->id, 'error' => $exception->getMessage()]);
            }
            foreach ($buildable as $project) {
                if (in_array($project->id, $active, true) && ! TaskVmWorkspaceTemplateLifecycle::isCurrent($templates, $project, $image)) {
                    BuildTaskVmWorkspaceTemplate::dispatch($host->nodeId, $project->id);
                }
            }
        }
    }
}
