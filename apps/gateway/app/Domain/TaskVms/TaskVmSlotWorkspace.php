<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Models\Project;

/**
 * The fixed workspace of a task VM slot: the warm checkout that the task image carries and that a claim fetches its branch onto,
 * `<apps>/<slug>/workspace` of the task VM account. The refresh bakes it there, and a slot's workspace Instance names it.
 */
final readonly class TaskVmSlotWorkspace
{
    public function __construct(private StorageRootResolver $roots, private NodeSettingsNormalizer $settings) {}

    public function checkoutPath(int $projectId): string
    {
        $project = Project::query()->findOrFail($projectId);

        return $this->roots->resolveApps($this->settings->fromStored(null), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'))
            ->append($project->slug)->append(TaskVmWorkspaceGuard::WorkspaceCheckout)->value;
    }
}
