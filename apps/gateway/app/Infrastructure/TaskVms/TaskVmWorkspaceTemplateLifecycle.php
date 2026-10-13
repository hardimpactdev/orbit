<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\TaskVms\TaskVmHost;
use App\Jobs\TaskVms\BuildTaskVmWorkspaceTemplate;
use App\Models\Node;
use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Which Projects get workspace templates, when a build is queued, and which templates go. Templates are built on
 * demand: a claim that finds no template for its VM's base image queues a build ({@see self::requestBuild()}), and the
 * refresh after a promote builds ahead only for recently active Projects and prunes what no claim can take. Builds run
 * on their own queue.
 */
final readonly class TaskVmWorkspaceTemplateLifecycle
{
    /** How long a host waits before a claim asks for another build of a Project whose build failed. */
    public const int FailureBackoffSeconds = 21600;

    /** A refresh builds templates ahead only for Projects with a group created within this many days. */
    public const int ActiveDays = 7;

    /**
     * After this many updates in a row the next build of a template is a full one. It starts the checkout without the
     * ignored files of older commits, and ends the chain of ZFS clones that keeps the data of each older template.
     */
    public const int MaxUpdates = 20;

    public function __construct(private TaskVmWorkspaceTemplateVolumes $volumes) {}

    /**
     * Why the Project cannot have a template, or null when it can. A build clones the default branch from the
     * repository, so it needs both. A Project without them gets no build, and no retry: only a change of the Project
     * helps.
     */
    public static function unbuildable(Project $project): ?string
    {
        if (! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)) {
            return 'the Project has no valid default branch';
        }

        return GitRepositoryOrigin::isValid($project->repository_url) ? null : 'the Project has no valid repository URL';
    }

    /** Whether the Project can have a template. When it cannot, it logs why, for the host when one is given. */
    public static function buildable(Project $project, ?int $hostNodeId = null): bool
    {
        $reason = self::unbuildable($project);
        if ($reason === null) {
            return true;
        }
        Log::warning('Task VM workspace template cannot be built; workspaces of the Project start from a fresh clone.', [
            'project_id' => $project->id, ...($hostNodeId === null ? [] : ['host_node_id' => $hostNodeId]), 'reason' => $reason,
        ]);

        return false;
    }

    /**
     * Queues a build of the Project's template on the host, unless its last build failed within the backoff. A
     * Project that cannot have a template gets a warning instead.
     */
    public static function requestBuild(TaskVmHost $host, Project $project): void
    {
        if (Cache::has(self::backoffKey($host->nodeId, $project->id)) || ! self::buildable($project, $host->nodeId)) {
            return;
        }
        BuildTaskVmWorkspaceTemplate::dispatch($host->nodeId, $project->id);
    }

    public static function backoffKey(int $hostNodeId, int $projectId): string
    {
        return "task-vms:workspace-template-backoff:{$hostNodeId}:{$projectId}";
    }

    /**
     * Whether `$volumes` hold a template that a claim of the Project on `$image` can take. A build would make the same
     * template again, so the refresh and the build job skip it. Without a current image nothing is current.
     *
     * @param  array<string, TaskVmWorkspaceTemplate>  $volumes
     */
    public static function isCurrent(array $volumes, Project $project, ?string $image): bool
    {
        return $image !== null && TaskVmWorkspaceTemplate::newest($volumes, $project->id, $project->repository_url, $image) instanceof TaskVmWorkspaceTemplate;
    }

    /**
     * Deletes the ready templates of `$projectId` on the host other than `$keep`, the template a build just published.
     * A template whose build has not finished stays: only a build, under the host's template lock, deletes those.
     * Incus keeps the data of a deleted template until its last clone is gone, so a running group never loses its
     * workspace.
     *
     * @return list<string> the deleted templates
     */
    public function prune(TaskVmHost $host, Node $hostNode, int $projectId, string $keep): array
    {
        return $this->deleteReady($host, $hostNode, $this->volumes->read($host, $hostNode), static fn (TaskVmWorkspaceTemplate $template): bool => $template->projectId === $projectId && $template->name !== $keep);
    }

    /**
     * Deletes every ready template of the host that no claim can take: the templates of Projects that are not in
     * `$repositories`, and of each Project there all but its newest template from that repository on `$image`, the
     * host's current base image. With no current image it keeps the newest template of the repository. So after a
     * promote, the host keeps at most one template for each Project, and none from the old image.
     *
     * Projects without a valid default branch or repository URL are not in `$repositories`, so their templates go too.
     *
     * @param  array<int, string>  $repositories  the repository URL of each Project that can have a template, by Project id
     * @param  array<string, TaskVmWorkspaceTemplate>  $volumes  the host's volumes
     * @return list<string> the deleted templates
     */
    public function pruneStale(TaskVmHost $host, Node $hostNode, array $repositories, ?string $image, array $volumes): array
    {
        $keep = [];
        foreach ($repositories as $projectId => $repository) {
            $keep[] = TaskVmWorkspaceTemplate::newest($volumes, $projectId, $repository, $image)?->name;
        }

        return $this->deleteReady($host, $hostNode, $volumes, static fn (TaskVmWorkspaceTemplate $template): bool => ! in_array($template->name, $keep, true));
    }

    /** Deletes the templates whose build did not finish. Only a build runs it, under the host's template lock. */
    public function deleteUnfinished(TaskVmHost $host, Node $hostNode): void
    {
        foreach ($this->volumes->read($host, $hostNode) as $template) {
            if ($template->isBuilding()) {
                $this->volumes->delete($host, $hostNode, $template->name);
            }
        }
    }

    /**
     * @param  array<string, TaskVmWorkspaceTemplate>  $volumes
     * @param  Closure(TaskVmWorkspaceTemplate): bool  $delete  whether to delete a ready template
     * @return list<string>
     */
    private function deleteReady(TaskVmHost $host, Node $hostNode, array $volumes, Closure $delete): array
    {
        $deleted = [];
        foreach ($volumes as $template) {
            if ($template->isReady() && $delete($template)) {
                $this->volumes->delete($host, $hostNode, $template->name);
                $deleted[] = $template->name;
            }
        }

        return $deleted;
    }
}
