<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly template refresh, after the base image refresh: on every configured host whose Node is active, it
 * deletes the templates of Projects that no longer run task VMs, and queues one template build for each Project
 * that does. A host that fails is logged, and the other hosts still run.
 */
final class RefreshTaskVmWorkspaceTemplates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function handle(TaskVmSettings $settings, TaskVmWorkspaceTemplates $templates): void
    {
        $projects = Project::query()->orderBy('id')->get()->filter(TaskVmWorkspaceTemplates::serves(...))->values();
        $ids = array_values($projects->map(static fn (Project $project): int => $project->id)->all());
        foreach ($settings->hosts as $host) {
            $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first();
            if (! $node instanceof Node) {
                continue;
            }
            try {
                $deleted = $templates->prune($host, $node, projects: $ids);
                if ($deleted !== []) {
                    Log::info('Deleted the workspace templates of Projects without task VMs.', ['host_node_id' => $node->id, 'volumes' => $deleted]);
                }
            } catch (Throwable $exception) {
                Log::error('The workspace template prune failed.', ['host_node_id' => $node->id, 'error' => $exception->getMessage()]);
            }
            foreach ($projects as $project) {
                BuildTaskVmWorkspaceTemplate::dispatch($host->nodeId, $project->id);
            }
        }
    }
}
