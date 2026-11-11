<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;

/** Read-only create conditions. Host bridge and shared-folder readiness remain in InstanceContainer::reserve(). */
final readonly class DevelopmentInstanceCreateAdmission
{
    public function assertSourceAccess(Project $project): void
    {
        if ($project->source_access === ProjectSourceAccess::GhCli) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Native creation requires anonymous or scoped GitHub App repository access.', 503);
        }
    }

    public function assertProject(Project $project, Node $node): void
    {
        $this->assertDefaults($project);
        $this->assertPlacement($node);
        $this->assertSourceProfile($project);
    }

    public function readyDefault(Project $project, ?Instance $default): Instance
    {
        $this->assertDefaultExists($project, $default);
        assert($default instanceof Instance);
        $this->assertContainer($project, $default);
        $this->assertActive($default);
        $this->assertSeed($default);

        return $default;
    }

    public function assertDefaultExists(Project $project, ?Instance $default): void
    {
        if ($default === null) {
            $this->missingDefault($project);
        }
    }

    public function assertContainer(Project $project, Instance $default): void
    {
        if ($default->runtime === null) {
            $this->missingDefault($project);
        }
    }

    private function missingDefault(Project $project): never
    {
        throw new ResourceOperationException('instance.default_missing',
            "Project [{$project->slug}] has no default Instance in a container that is active and has a seed snapshot.", 409);
    }

    public function assertActive(Instance $default): void
    {
        if ($default->status !== InstanceState::Active) {
            $this->missingSeed();
        }
    }

    public function assertSeed(Instance $default): void
    {
        if ($default->runtime?->seed_commit === null) {
            $this->missingSeed();
        }
    }

    private function missingSeed(): never
    {
        throw new ResourceOperationException('instance.default_missing', 'The Project\'s default Instance has no seed snapshot yet: finish its create first.', 409);
    }

    public function assertRuntime(): void
    {
        $this->assertRuntimeContributions();
        $this->assertModelRoute();
    }

    public function assertRuntimeContributions(): void
    {
        app(InstanceLifecycleContributions::class)->assertCreateAvailable();
    }

    public function assertModelRoute(): void
    {
        if (! app()->bound(TaskVmModelRoute::class)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'A clone needs the model router.', 503);
        }
        app(TaskVmModelRoute::class)->assertReady();
    }

    public function assertDefaults(Project $project): void
    {
        if (! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)
            || ! is_string($project->root) || ! ProjectRoot::isValid($project->root, $project->type)) {
            throw new ResourceOperationException('project.source_defaults_incomplete', "App [{$project->slug}] does not have complete source defaults.");
        }
        GitRepositoryOrigin::validate($project->repository_url);
    }

    public function assertPlacement(Node $node): void
    {
        $this->assertProductionPlacement($node);
        $this->assertNodeActiveLinux($node);
        $this->assertAppDevRole($node);
    }

    public function assertProductionPlacement(Node $node): void
    {
        if ($node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppProd && $role->status === LifecycleStatus::Active)) {
            throw new ResourceOperationException('instance.candidate_required', 'Production Instances require instance:clone.', 409);
        }
    }

    public function assertNodeActiveLinux(Node $node): void
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw new ResourceOperationException('instance.node_inactive', 'The selected app-dev Node is not active.');
        }
    }

    public function assertAppDevRole(Node $node): void
    {
        if (! $node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active)) {
            throw new ResourceOperationException('instance.node_not_app_dev', 'The selected Node has no active app-dev role.');
        }
    }

    public function assertSourceProfile(Project $project): void
    {
        if ($project->source_profile_error !== null) {
            throw new ResourceOperationException($project->source_profile_error, 'The Project source metadata is invalid or unsupported for a development Instance.', 422);
        }
    }
}
