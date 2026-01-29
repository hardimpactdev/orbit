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

/**
 * Service for workspace management.
 */
class WorkspaceService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command
    ) {}

    /**
     * List all workspaces.
     */
    public function workspacesList(Environment $environment): array
    {
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
        if ($environment->is_local) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->command->executeCommand($environment, "workspace:remove {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->connector->sendRequest($environment, new RemoveWorkspaceProjectRequest($workspace, $project));
    }
}
