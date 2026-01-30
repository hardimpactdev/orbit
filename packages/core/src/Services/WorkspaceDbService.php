<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Workspace;

/**
 * Database-backed workspace service.
 * Used when CLI is not available (desktop app without CLI installed).
 */
class WorkspaceDbService
{
    /**
     * List all workspaces for an environment.
     */
    public function workspacesList(Environment $environment): array
    {
        $workspaces = Workspace::where('environment_id', $environment->id)->get();

        return [
            'success' => true,
            'data' => [
                'workspaces' => collect($workspaces)->map(fn (Workspace $w): array => $w->toFrontendArray())->all(),
            ],
        ];
    }

    /**
     * Create a new workspace.
     */
    public function workspaceCreate(Environment $environment, string $name): array
    {
        // Check if workspace already exists
        $existing = Workspace::where('environment_id', $environment->id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            return [
                'success' => false,
                'error' => "Workspace '{$name}' already exists",
            ];
        }

        // Determine workspace path
        $config = $environment->metadata ?? [];
        $basePath = $config['workspaces_path'] ?? ($environment->is_local ? $this->getDefaultWorkspacesPath() : null);
        $workspacePath = $basePath ? rtrim($basePath, '/') . '/' . $name : null;

        $workspace = Workspace::create([
            'environment_id' => $environment->id,
            'name' => $name,
            'path' => $workspacePath,
            'projects' => [],
        ]);

        // Create workspace directory if path is set and we're on local
        if ($workspacePath && $environment->is_local) {
            $this->createWorkspaceDirectory($workspace);
        }

        return [
            'success' => true,
            'data' => [
                'workspace' => $workspace->toFrontendArray(),
            ],
        ];
    }

    /**
     * Delete a workspace.
     */
    public function workspaceDelete(Environment $environment, string $name): array
    {
        $workspace = Workspace::where('environment_id', $environment->id)
            ->where('name', $name)
            ->first();

        if (!$workspace) {
            return [
                'success' => false,
                'error' => "Workspace '{$name}' not found",
            ];
        }

        $workspace->delete();

        return [
            'success' => true,
            'message' => "Workspace '{$name}' deleted",
        ];
    }

    /**
     * Add a project to a workspace.
     */
    public function workspaceAddProject(Environment $environment, string $workspaceName, string $projectName): array
    {
        $workspace = Workspace::where('environment_id', $environment->id)
            ->where('name', $workspaceName)
            ->first();

        if (!$workspace) {
            return [
                'success' => false,
                'error' => "Workspace '{$workspaceName}' not found",
            ];
        }

        $workspace->addProject($projectName);

        // Update .code-workspace file if path exists
        if ($workspace->path && $environment->is_local) {
            $this->updateWorkspaceFile($workspace);
        }

        return [
            'success' => true,
            'data' => [
                'workspace' => $workspace->fresh()->toFrontendArray(),
            ],
        ];
    }

    /**
     * Remove a project from a workspace.
     */
    public function workspaceRemoveProject(Environment $environment, string $workspaceName, string $projectName): array
    {
        $workspace = Workspace::where('environment_id', $environment->id)
            ->where('name', $workspaceName)
            ->first();

        if (!$workspace) {
            return [
                'success' => false,
                'error' => "Workspace '{$workspaceName}' not found",
            ];
        }

        $workspace->removeProject($projectName);

        // Update .code-workspace file if path exists
        if ($workspace->path && $environment->is_local) {
            $this->updateWorkspaceFile($workspace);
        }

        return [
            'success' => true,
            'data' => [
                'workspace' => $workspace->fresh()->toFrontendArray(),
            ],
        ];
    }

    /**
     * Get the default workspaces path for local environments.
     */
    protected function getDefaultWorkspacesPath(): string
    {
        $home = $_SERVER['HOME'] ?? null;
        if ($home === null) {
            $home = getenv('HOME') ?: '/tmp';
        }
        return $home . '/Workspaces';
    }

    /**
     * Create the workspace directory and initial files.
     */
    protected function createWorkspaceDirectory(Workspace $workspace): void
    {
        if (!$workspace->path) {
            return;
        }

        // Create directory
        if (!is_dir($workspace->path)) {
            mkdir($workspace->path, 0755, true);
        }

        // Create .code-workspace file
        $this->updateWorkspaceFile($workspace);
    }

    /**
     * Update the .code-workspace file with current projects.
     */
    protected function updateWorkspaceFile(Workspace $workspace): void
    {
        if (!$workspace->path) {
            return;
        }

        $workspaceFile = rtrim($workspace->path, '/') . '/' . $workspace->name . '.code-workspace';
        
        $content = [
            'folders' => collect($workspace->projects ?? [])->map(fn($project) => [
                'path' => '../' . $project,
            ])->all(),
            'settings' => (object)[],
        ];

        file_put_contents($workspaceFile, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
