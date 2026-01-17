<?php

namespace App\Services\OrbitCli;

use App\Http\Integrations\Orbit\Requests\CreateProjectRequest;
use App\Http\Integrations\Orbit\Requests\DeleteProjectRequest;
use App\Http\Integrations\Orbit\Requests\GetProjectsRequest;
use App\Http\Integrations\Orbit\Requests\GetProvisionStatusRequest;
use App\Http\Integrations\Orbit\Requests\RebuildProjectRequest;
use App\Models\Environment;
use App\Services\OrbitCli\Shared\CommandService;
use App\Services\OrbitCli\Shared\ConnectorService;
use App\Services\SshService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Service for project management operations.
 */
class ProjectService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh,
        protected ConfigurationService $config
    ) {}

    /**
     * Get all projects from CLI (fresh, no caching).
     * Returns all directories in scan paths, with has_public_folder flag.
     */
    public function projectList(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, 'project:list --json');
        }

        return $this->connector->sendRequest($environment, new GetProjectsRequest);
    }

    /**
     * Create a new project via the CLI.
     *
     * @param  array  $options  Array containing: name, org (optional), template (optional), is_template (optional), directory (optional), visibility (optional), db_driver, session_driver, cache_driver, queue_driver
     */
    public function createProject(Environment $environment, array $options): array
    {
        // Build request payload
        $payload = [
            'name' => $options['name'],
            'visibility' => $options['visibility'] ?? 'private',
        ];

        // GitHub organization to create repo under (defaults to user's personal account if not set)
        if (! empty($options['org'])) {
            $payload['organization'] = $options['org'];
        }

        // Handle template vs clone
        if (! empty($options['template'])) {
            $isTemplate = $options['is_template'] ?? false;
            if ($isTemplate) {
                $payload['template'] = $options['template'];
            } else {
                // Convert repo to clone URL
                $repo = $options['template'];
                if (! str_starts_with((string) $repo, 'git@') && ! str_starts_with((string) $repo, 'https://')) {
                    $payload['clone_url'] = "git@github.com:{$repo}.git";
                } else {
                    $payload['clone_url'] = $repo;
                }

                if (! empty($options['fork'])) {
                    $payload['fork'] = true;
                }
            }
        }

        // Optional fields
        if (! empty($options['directory'])) {
            $payload['path'] = $options['directory'];
        }
        if (! empty($options['db_driver'])) {
            $payload['db_driver'] = $options['db_driver'];
        }
        if (! empty($options['session_driver'])) {
            $payload['session_driver'] = $options['session_driver'];
        }
        if (! empty($options['cache_driver'])) {
            $payload['cache_driver'] = $options['cache_driver'];
        }
        if (! empty($options['queue_driver'])) {
            $payload['queue_driver'] = $options['queue_driver'];
        }
        if (! empty($options['php_version'])) {
            $payload['php_version'] = $options['php_version'];
        }

        $result = $this->connector->sendRequest($environment, new CreateProjectRequest($payload));

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'slug' => $result['slug'] ?? Str::slug($options['name']),
                    'status' => 'provisioning',
                    'message' => $result['message'] ?? 'Project creation queued',
                ],
            ];
        }

        return $result;
    }

    /**
     * Rebuild a project (re-run deps install, build, migrations without git pull).
     */
    public function rebuild(Environment $environment, string $site): array
    {
        if ($environment->is_local) {
            $escapedSite = escapeshellarg($site);

            return $this->command->executeCommand($environment, "project:update --site={$escapedSite} --no-git --json");
        }

        return $this->connector->sendRequest($environment, new RebuildProjectRequest($site));
    }

    /**
     * Scan for existing projects on a server.
     */
    public function scanProjects(Environment $environment, ?string $path = null, int $depth = 2): array
    {
        $command = 'project:scan';

        if ($path) {
            $command .= ' '.escapeshellarg($path);
        }

        $command .= ' --depth='.escapeshellarg((string) $depth);
        $command .= ' --json';

        return $this->command->executeCommand($environment, $command);
    }

    /**
     * Update a project (git pull + dependencies + migrations).
     */
    public function updateProject(Environment $environment, string $path, array $options = []): array
    {
        $escapedPath = escapeshellarg($path);
        $command = "project:update {$escapedPath}";

        if (! empty($options['no_deps'])) {
            $command .= ' --no-deps';
        }

        if (! empty($options['no_migrate'])) {
            $command .= ' --no-migrate';
        }

        $command .= ' --json';

        return $this->command->executeCommand($environment, $command);
    }

    /**
     * Delete a project's files from the filesystem.
     * Note: Orchestrator deletion (VK/Linear cleanup) should be done separately via OrchestratorService.
     */
    public function deleteProject(Environment $environment, string $slug, bool $force = false): array
    {
        if (! $environment->is_local) {
            return $this->connector->sendRequest($environment, new DeleteProjectRequest($slug));
        }

        // For local environments, use SSH-based deletion

        // Get the project path from config
        $config = $this->config->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Could not read orbit config'];
        }

        $paths = $config['data']['paths'] ?? ['~/projects'];
        $projectPath = null;

        // Find the project directory
        foreach ($paths as $basePath) {
            $checkPath = rtrim((string) $basePath, '/').'/'.$slug;
            $expandedPath = str_starts_with($checkPath, '~/') ? '$HOME'.substr($checkPath, 1) : $checkPath;

            // Check if directory exists
            $checkResult = $this->ssh->execute($environment, "test -d {$expandedPath} && echo 'exists'");
            if ($checkResult['success'] && str_contains($checkResult['output'] ?? '', 'exists')) {
                $projectPath = $expandedPath;
                break;
            }
        }

        if (! $projectPath) {
            // Project directory doesn't exist - that's fine, maybe already deleted
            return ['success' => true, 'data' => ['message' => 'Project directory not found (already deleted?)']];
        }

        // Delete the project directory (sudo needed because FrankenPHP runs as root and creates root-owned cache files)
        $deleteResult = $this->ssh->execute($environment, "sudo rm -rf {$projectPath}");
        if (! $deleteResult['success']) {
            return ['success' => false, 'error' => 'Failed to delete project directory: '.($deleteResult['error'] ?? 'Unknown error')];
        }

        // Regenerate Caddy config to remove the site
        $this->command->executeCommand($environment, 'sites --json'); // This triggers Caddy regeneration

        return ['success' => true, 'data' => ['message' => "Project '{$slug}' deleted from filesystem", 'path' => $projectPath]];
    }

    /**
     * Check if a GitHub repository already exists.
     * Used to validate project names BEFORE starting provisioning.
     *
     * @param  string  $repo  Repository in "owner/name" format (e.g., "nckrtl/my-project")
     * @return array{exists: bool, error?: string}
     */
    public function checkGitHubRepoExists(Environment $environment, string $repo): array
    {
        // Use gh repo view to check if repo exists
        $escapedRepo = escapeshellarg($repo);
        $command = "gh repo view {$escapedRepo} --json name 2>/dev/null && echo 'EXISTS' || echo 'NOT_FOUND'";

        $result = $this->ssh->execute($environment, $command, 15);

        if (! $result['success']) {
            return [
                'exists' => false,
                'error' => 'Failed to check repository: '.($result['error'] ?? 'SSH error'),
            ];
        }

        $output = trim((string) $result['output']);

        // If output contains JSON followed by EXISTS, the repo exists
        if (str_contains($output, 'EXISTS')) {
            return ['exists' => true];
        }

        return ['exists' => false];
    }

    /**
     * Get the GitHub username/org for new repos.
     * Gets from config (for remote) or queries gh CLI (for local).
     */
    public function getGitHubUser(Environment $environment): ?string
    {
        // For remote environments, get from config API (no SSH needed)
        if (! $environment->is_local) {
            $config = $this->config->getConfig($environment);
            if ($config['success'] && ! empty($config['data']['github_username'])) {
                return $config['data']['github_username'];
            }
        }

        // For local environments, query gh CLI directly
        $command = 'gh api user --jq .login 2>/dev/null';
        if ($environment->is_local) {
            $result = Process::timeout(10)->run($command);
            if ($result->successful() && trim($result->output())) {
                return trim($result->output());
            }
        } else {
            // Fallback to SSH if not in config (shouldn't happen normally)
            $result = $this->ssh->execute($environment, $command, 10);
            if ($result['success'] && ! empty($result['output'])) {
                return trim((string) $result['output']);
            }
        }

        return null;
    }

    /**
     * Get GitHub organizations the user belongs to.
     * Returns array of orgs with login and avatar_url.
     *
     * @return array{success: bool, data?: array<array{login: string, avatar_url: string}>, error?: string}
     */
    public function getGitHubOrgs(Environment $environment): array
    {
        // Query gh CLI for user's organizations
        $command = "gh api user/orgs --jq '[.[] | {login: .login, avatar_url: .avatar_url}]' 2>/dev/null";

        if ($environment->is_local) {
            $result = Process::timeout(10)->run($command);
            if ($result->successful() && trim($result->output())) {
                $orgs = json_decode(trim($result->output()), true);
                if (is_array($orgs)) {
                    return ['success' => true, 'data' => $orgs];
                }
            }

            return ['success' => true, 'data' => []];
        }

        // For remote environments, use SSH
        $result = $this->ssh->execute($environment, $command, 10);
        if ($result['success'] && ! empty($result['output'])) {
            $orgs = json_decode(trim((string) $result['output']), true);
            if (is_array($orgs)) {
                return ['success' => true, 'data' => $orgs];
            }
        }

        return ['success' => true, 'data' => []];
    }

    /**
     * Check the provisioning status of a project.
     */
    public function provisionStatus(Environment $environment, string $slug): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, 'provision:status '.escapeshellarg($slug).' --json');
        }

        return $this->connector->sendRequest($environment, new GetProvisionStatusRequest($slug));
    }

    /**
     * Setup a Laravel project (configure env, create database, run composer setup).
     */
    public function setupProject(Environment $environment, string $project): array
    {
        $escapedProject = escapeshellarg($project);

        return $this->command->executeCommand($environment, "setup {$escapedProject} --json");
    }
}
