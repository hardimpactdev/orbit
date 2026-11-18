<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Domain\Instances\ContainerServer;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Tasks\TaskPrMode;

final readonly class UpdateProjectData
{
    public function __construct(
        public bool $typeProvided,
        public ?ProjectType $type,
        public bool $slugProvided,
        public ?string $slug,
        public bool $repositoryUrlProvided,
        public ?string $repositoryUrl,
        public bool $defaultBranchProvided,
        public ?string $defaultBranch,
        public bool $rootProvided,
        public ?string $root,
        public ?string $code = null,
        public bool $taskCheckProvided = false,
        public ?string $taskCheck = null,
        public bool $sourceAccessProvided = false,
        public ?ProjectSourceAccess $sourceAccess = null,
        public bool $taskWorkspaceRoutedProvided = false,
        public bool $taskWorkspaceRouted = true,
        public bool $reviewAndMergeProvided = false,
        public bool $reviewAndMerge = false,
        public bool $mergeCheckProvided = false,
        public ?string $mergeCheck = null,
        public ?string $name = null,
        public bool $nestedFleetProvided = false,
        public bool $nestedFleet = false,
        /** @var array<string, string|null> the copy turn agent defaults to change, keyed by Project column; null clears one (TaskAgent) */
        public array $taskAgents = [],
        public bool $taskPrModeProvided = false,
        /** The mode of the Project's task copy groups that set none; null clears it, so the Gateway's default applies. */
        public ?TaskPrMode $taskPrMode = null,
        /** Whether each new commit of the default branch deploys the container default (docs/reference/deployments.md#automatic-development-defaults). */
        public bool $defaultAutoDeployProvided = false,
        public bool $defaultAutoDeploy = true,
        /** The server of the Project's containers that Orbit reserves after the change; null leaves it. */
        public ?ContainerServer $containerServer = null,
    ) {}

    public function hasChanges(): bool
    {
        return $this->code !== null
            || $this->name !== null
            || $this->typeProvided
            || $this->slugProvided
            || $this->repositoryUrlProvided
            || $this->defaultBranchProvided
            || $this->rootProvided
            || $this->taskCheckProvided
            || $this->sourceAccessProvided
            || $this->taskWorkspaceRoutedProvided
            || $this->reviewAndMergeProvided
            || $this->mergeCheckProvided || $this->nestedFleetProvided || $this->taskAgents !== [] || $this->taskPrModeProvided || $this->defaultAutoDeployProvided
            || $this->containerServer !== null;
    }

    /** The nested-fleet flag travels alone, so its refusal has no other write to undo. */
    public function changesBesideNestedFleet(): bool
    {
        return $this->code !== null || $this->name !== null || $this->typeProvided || $this->sourceAccessProvided
            || $this->taskCheckProvided || $this->taskWorkspaceRoutedProvided || $this->reviewAndMergeProvided || $this->mergeCheckProvided
            || $this->taskAgents !== [] || $this->taskPrModeProvided || $this->defaultAutoDeployProvided || $this->hasReconcilableChanges()
            || $this->containerServer !== null;
    }

    /** Settings that change only the Project row, under the Instance operation lock. */
    public function hasTaskSettings(): bool
    {
        return $this->name !== null || $this->taskCheckProvided || $this->taskWorkspaceRoutedProvided
            || $this->reviewAndMergeProvided || $this->mergeCheckProvided || $this->nestedFleetProvided || $this->taskAgents !== [] || $this->taskPrModeProvided || $this->defaultAutoDeployProvided
            || $this->containerServer !== null;
    }

    public function hasReconcilableChanges(): bool
    {
        return $this->slugProvided
            || $this->repositoryUrlProvided
            || $this->defaultBranchProvided
            || $this->rootProvided;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->typeProvided ? $this->type?->value : null,
            'slug' => $this->slugProvided ? $this->slug : null,
            'repository_url' => $this->repositoryUrlProvided ? $this->repositoryUrl : null,
            'default_branch' => $this->defaultBranchProvided ? $this->defaultBranch : null,
            'root' => $this->rootProvided ? $this->root : null,
            'task_check' => $this->taskCheckProvided ? $this->taskCheck : null,
            'source_access' => $this->sourceAccessProvided ? $this->sourceAccess?->value : null,
            'task_workspace_routed' => $this->taskWorkspaceRoutedProvided ? $this->taskWorkspaceRouted : null,
            'review_and_merge' => $this->reviewAndMergeProvided ? $this->reviewAndMerge : null,
            'merge_check' => $this->mergeCheckProvided ? $this->mergeCheck : null,
            'nested_fleet' => $this->nestedFleetProvided ? $this->nestedFleet : null,
            'default_auto_deploy' => $this->defaultAutoDeployProvided ? $this->defaultAutoDeploy : null,
            'task_agents' => $this->taskAgents,
            'container_server' => $this->containerServer?->value,
            'provided' => [
                $this->typeProvided,
                $this->slugProvided,
                $this->repositoryUrlProvided,
                $this->defaultBranchProvided,
                $this->rootProvided,
                $this->taskCheckProvided,
                $this->sourceAccessProvided,
                $this->taskWorkspaceRoutedProvided,
                $this->reviewAndMergeProvided,
                $this->mergeCheckProvided,
                $this->nestedFleetProvided,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
