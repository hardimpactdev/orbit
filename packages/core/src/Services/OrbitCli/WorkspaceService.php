<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\AddWorkspaceProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateWorkspaceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteWorkspaceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetWorkspacesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RemoveWorkspaceProjectRequest;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\WorkspaceDbService;

/**
 * Service for workspace management.
 * Uses CLI when available, falls back to database otherwise.
 */
class WorkspaceService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected WorkspaceDbService $dbService
    ) {}

    /**
     * Check if CLI is available for local environments.
     */
    protected function shouldUseCli(Environment $environment): bool
    {
        if (! $environment->is_local) {
            return true; // Remote always uses CLI/API
        }

        return $this->command->isLocalCliInstalled();
    }

    /**
     * List all workspaces.
     */
    public function workspacesList(Environment $environment): array
    {
        if ($environment->is_local && ! $this->shouldUseCli($environment)) {
            return $this->dbService->workspacesList($environment);
        }

        if ($environment->is_local) {
            return $this->command->executeCommand($environment, 'workspaces --json');
        }

        return $this->connector->sendRequest($environment, new GetWorkspacesRequest);
    }

    /**
     * Create a new workspace.
     */
    public function workspaceCreate(Environment $environment, string $name): array
    {
        if ($environment->is_local && ! $this->shouldUseCli($environment)) {
            return $this->dbService->workspaceCreate($environment, $name);
        }

        if ($environment->is_local) {
            $escapedName = escapeshellarg($name);

            return $this->command->executeCommand($environment, "workspace:create {$escapedName} --json");
        }

        return $this->connector->sendRequest($environment, new CreateWorkspaceRequest($name));
    }

    /**
     * Delete a workspace.
     */
    public function workspaceDelete(Environment $environment, string $name): array
    {
        if ($environment->is_local && ! $this->shouldUseCli($environment)) {
            return $this->dbService->workspaceDelete($environment, $name);
        }

        if ($environment->is_local) {
            $escapedName = escapeshellarg($name);

            return $this->command->executeCommand($environment, "workspace:delete {$escapedName} --force --json");
        }

        return $this->connector->sendRequest($environment, new DeleteWorkspaceRequest($name));
    }

    /**
     * Add a project to a workspace.
     */
    public function workspaceAddProject(Environment $environment, string $workspace, string $project): array
    {
        if ($environment->is_local && ! $this->shouldUseCli($environment)) {
            return $this->dbService->workspaceAddProject($environment, $workspace, $project);
        }

        if ($environment->is_local) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->command->executeCommand($environment, "workspace:add {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->connector->sendRequest($environment, new AddWorkspaceProjectRequest($workspace, $project));
    }

    /**
     * Remove a project from a workspace.
     */
    public function workspaceRemoveProject(Environment $environment, string $workspace, string $project): array
    {
        if ($environment->is_local && ! $this->shouldUseCli($environment)) {
            return $this->dbService->workspaceRemoveProject($environment, $workspace, $project);
        }

        if ($environment->is_local) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->command->executeCommand($environment, "workspace:remove {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->connector->sendRequest($environment, new RemoveWorkspaceProjectRequest($workspace, $project));
    }
}
