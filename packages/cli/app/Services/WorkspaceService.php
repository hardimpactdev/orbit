<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class WorkspaceService
{
    protected string $workspacesDir;

    protected string $projectsDir;

    public function __construct()
    {
        $this->workspacesDir = getenv('HOME').'/workspaces';
        $this->projectsDir = getenv('HOME').'/projects';
    }

    public function ensureWorkspacesDirectory(): void
    {
        if (! File::isDirectory($this->workspacesDir)) {
            File::makeDirectory($this->workspacesDir, 0755, true);
        }
    }

    public function list(): array
    {
        $this->ensureWorkspacesDirectory();

        $workspaces = [];
        $dirs = File::directories($this->workspacesDir);

        foreach ($dirs as $dir) {
            $name = basename((string) $dir);
            $workspaces[] = $this->getWorkspaceInfo($name);
        }

        return $workspaces;
    }

    public function getWorkspaceInfo(string $name): array
    {
        $path = $this->workspacesDir.'/'.$name;
        $projects = [];

        if (File::isDirectory($path)) {
            foreach (File::directories($path) as $item) {
                if (is_link($item)) {
                    $target = readlink($item);
                    $projects[] = [
                        'name' => basename((string) $item),
                        'path' => $target,
                    ];
                }
            }

            // Also check for symlinks that are files (shouldn't happen but just in case)
            foreach (scandir($path) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $itemPath = $path.'/'.$item;
                if (is_link($itemPath) && ! in_array(basename($item), array_column($projects, 'name'))) {
                    $target = readlink($itemPath);
                    $projects[] = [
                        'name' => basename($item),
                        'path' => $target,
                    ];
                }
            }
        }

        return [
            'name' => $name,
            'path' => $path,
            'projects' => $projects,
            'project_count' => count($projects),
            'has_workspace_file' => File::exists($path.'/'.$name.'.code-workspace'),
            'has_claude_md' => File::exists($path.'/CLAUDE.md'),
        ];
    }

    public function create(string $name): array
    {
        $this->ensureWorkspacesDirectory();

        $path = $this->workspacesDir.'/'.$name;

        if (File::isDirectory($path)) {
            throw new \RuntimeException("Workspace '{$name}' already exists");
        }

        File::makeDirectory($path, 0755, true);

        // Create initial CLAUDE.md
        $claudeMd = "# {$name} Workspace\n\n".
            "This workspace groups related projects together.\n\n".
            "## Projects\n\n".
            "_No projects added yet._\n";
        File::put($path.'/CLAUDE.md', $claudeMd);

        // Create initial .code-workspace file
        $this->regenerateWorkspaceFile($name);

        return $this->getWorkspaceInfo($name);
    }

    public function delete(string $name): void
    {
        $path = $this->workspacesDir.'/'.$name;

        if (! File::isDirectory($path)) {
            throw new \RuntimeException("Workspace '{$name}' does not exist");
        }

        // Remove all symlinks and files
        File::deleteDirectory($path);
    }

    public function addProject(string $workspace, string $project): array
    {
        $workspacePath = $this->workspacesDir.'/'.$workspace;
        $projectPath = $this->projectsDir.'/'.$project;
        $symlinkPath = $workspacePath.'/'.$project;

        if (! File::isDirectory($workspacePath)) {
            throw new \RuntimeException("Workspace '{$workspace}' does not exist");
        }

        if (! File::isDirectory($projectPath)) {
            throw new \RuntimeException("Project '{$project}' does not exist in projects directory");
        }

        if (is_link($symlinkPath) || File::exists($symlinkPath)) {
            throw new \RuntimeException("Project '{$project}' is already in workspace '{$workspace}'");
        }

        symlink($projectPath, $symlinkPath);

        $this->regenerateWorkspaceFile($workspace);
        $this->updateClaudeMd($workspace);

        return $this->getWorkspaceInfo($workspace);
    }

    public function removeProject(string $workspace, string $project): array
    {
        $workspacePath = $this->workspacesDir.'/'.$workspace;
        $symlinkPath = $workspacePath.'/'.$project;

        if (! File::isDirectory($workspacePath)) {
            throw new \RuntimeException("Workspace '{$workspace}' does not exist");
        }

        if (! is_link($symlinkPath)) {
            throw new \RuntimeException("Project '{$project}' is not in workspace '{$workspace}'");
        }

        unlink($symlinkPath);

        $this->regenerateWorkspaceFile($workspace);
        $this->updateClaudeMd($workspace);

        return $this->getWorkspaceInfo($workspace);
    }

    protected function regenerateWorkspaceFile(string $workspace): void
    {
        $info = $this->getWorkspaceInfo($workspace);
        $workspacePath = $this->workspacesDir.'/'.$workspace;

        $folders = [];
        foreach ($info['projects'] as $project) {
            $folders[] = [
                'name' => ucwords(str_replace(['-', '_'], ' ', $project['name'])),
                'path' => './'.$project['name'],
            ];
        }

        $workspaceFile = [
            'folders' => $folders,
            'settings' => (object) [],
        ];

        File::put(
            $workspacePath.'/'.$workspace.'.code-workspace',
            json_encode($workspaceFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function updateClaudeMd(string $workspace): void
    {
        $info = $this->getWorkspaceInfo($workspace);
        $workspacePath = $this->workspacesDir.'/'.$workspace;

        $projectsList = '';
        if (empty($info['projects'])) {
            $projectsList = "_No projects added yet._\n";
        } else {
            foreach ($info['projects'] as $project) {
                $projectsList .= '- **'.ucwords(str_replace(['-', '_'], ' ', $project['name'])).'** ('.$project['name'].")\n";
            }
        }

        $claudeMd = '# '.ucwords(str_replace(['-', '_'], ' ', $workspace))." Workspace\n\n".
            "This workspace groups related projects together.\n\n".
            "## Projects\n\n".
            $projectsList;

        File::put($workspacePath.'/CLAUDE.md', $claudeMd);
    }
}
