<?php

namespace HardImpact\Orbit\Core\Services\OrbitCli;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetProjectsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetProvisionStatusRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RebuildProjectRequest;
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Service for project management operations via CLI.
 */
class ProjectCliService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh,
        protected ConfigurationService $config
    ) {}

    /**
     * Sync all projects from CLI to database.
     * Creates new projects, updates existing ones, removes orphans.
     */
    public function syncAllProjectsFromCli(Environment $environment): array
    {
        if ($environment->is_local) {
            $result = $this->command->executeCommand($environment, 'project:list --json');
        } else {
            $result = $this->connector->sendRequest($environment, new GetProjectsRequest);
        }

        if (! $result['success']) {
            return $result;
        }

        $cliProjects = collect($result['data']['projects'] ?? []);
        $cliSlugs = $cliProjects->pluck('name')->toArray();

        // Update or create projects from CLI
        foreach ($cliProjects as $cliProject) {
            $slug = $cliProject['name'];
            $project = Project::where('environment_id', $environment->id)
                ->where(fn ($q) => $q->where('slug', $slug)->orWhere('name', $slug))
                ->first();

            $data = [
                'environment_id' => $environment->id,
                'slug' => $slug,
                'name' => $slug,
                'display_name' => $cliProject['display_name'] ?? ucwords(str_replace(['-', '_'], ' ', $slug)),
                'github_repo' => $cliProject['github_repo'] ?? null,
                'project_type' => $cliProject['project_type'] ?? 'unknown',
                'has_public_folder' => $cliProject['has_public_folder'] ?? false,
                'domain' => $cliProject['domain'] ?? null,
                'url' => $cliProject['url'] ?? null,
                'php_version' => $cliProject['php_version'] ?? '8.4',
                'path' => $cliProject['path'] ?? '',
                'status' => 'active',
            ];

            if ($project) {
                $project->update($data);
            } else {
                Project::create($data);
            }
        }

        // Remove orphan projects (in DB but not in CLI)
        Project::where('environment_id', $environment->id)
            ->whereNotIn('slug', $cliSlugs)
            ->whereNotIn('name', $cliSlugs)
            ->delete();

        return [
            'success' => true,
            'data' => [
                'synced' => count($cliSlugs),
            ],
        ];
    }

    public function syncProjectFromCli(Environment $environment, Project $project, string $slug): array
    {
        if ($environment->is_local) {
            $result = $this->command->executeCommand($environment, 'project:list --json');
        } else {
            $result = $this->connector->sendRequest($environment, new GetProjectsRequest);
        }

        if (! $result['success']) {
            return $result;
        }

        $projects = $result['data']['projects'] ?? [];
        $matched = collect($projects)->first(function (array $candidate) use ($slug) {
            return ($candidate['name'] ?? null) === $slug || ($candidate['slug'] ?? null) === $slug;
        });

        if (! $matched) {
            return ['success' => false, 'error' => 'Project not found in CLI list'];
        }

        $project->update([
            'display_name' => $matched['display_name'] ?? $project->display_name,
            'github_repo' => $matched['github_repo'] ?? $project->github_repo,
            'project_type' => $matched['project_type'] ?? $project->project_type,
            'has_public_folder' => $matched['has_public_folder'] ?? $project->has_public_folder,
            'domain' => $matched['domain'] ?? $project->domain,
            'url' => $matched['url'] ?? $project->url,
            'php_version' => $matched['php_version'] ?? $project->php_version,
            'path' => $matched['path'] ?? $project->path,
            'status' => Arr::get($matched, 'status', $project->status),
        ]);

        return ['success' => true, 'data' => $matched];
    }

    /**
     * Get all projects from CLI (fresh, no caching).
     * Returns all directories in scan paths, with has_public_folder flag.
     */
    public function projectList(Environment $environment): array
    {
        $projects = Project::query()
            ->where('environment_id', $environment->id)
            ->orderBy('name')
            ->get();

        $config = $this->config->getConfig($environment);
        $configData = $config['success'] ? ($config['data'] ?? []) : [];

        return [
            'success' => true,
            'data' => [
                'projects' => $projects,
                'tld' => $configData['tld'] ?? 'test',
                'default_php_version' => $configData['default_php_version'] ?? '8.4',
                'available_php_versions' => $configData['available_php_versions'] ?? ['8.3', '8.4', '8.5'],
            ],
        ];
    }

    /**
     * Create a new project via the CLI.
     *
     * @param  array  $options  Array containing: name, org (optional), template (optional), is_template (optional), directory (optional), visibility (optional), db_driver, session_driver, cache_driver, queue_driver
     */
    public function createProject(Environment $environment, array $options): array
    {
        // For local environments, use CLI command directly
        if ($environment->is_local) {
            return $this->createProjectViaCommand($environment, $options);
        }

        // For remote environments, use HTTP API
        return $this->createProjectViaHttp($environment, $options);
    }

    /**
     * Create project via CLI command (for local environments).
     */
    protected function createProjectViaCommand(Environment $environment, array $options): array
    {
        $name = escapeshellarg($options['name']);
        $command = "project:create {$name}";

        // Handle template vs clone
        if (! empty($options['template'])) {
            $isTemplate = $options['is_template'] ?? false;
            if ($isTemplate) {
                $command .= ' --template='.escapeshellarg($options['template']);
            } else {
                $command .= ' --clone='.escapeshellarg($options['template']);
                if (! empty($options['fork'])) {
                    $command .= ' --fork';
                }
            }
        }

        // Optional flags
        if (! empty($options['visibility'])) {
            $command .= ' --visibility='.escapeshellarg($options['visibility']);
        }
        if (! empty($options['directory'])) {
            $command .= ' --path='.escapeshellarg($options['directory']);
        }
        if (! empty($options['php_version'])) {
            $command .= ' --php='.escapeshellarg($options['php_version']);
        }
        if (! empty($options['db_driver'])) {
            $command .= ' --db-driver='.escapeshellarg($options['db_driver']);
        }
        if (! empty($options['session_driver'])) {
            $command .= ' --session-driver='.escapeshellarg($options['session_driver']);
        }
        if (! empty($options['cache_driver'])) {
            $command .= ' --cache-driver='.escapeshellarg($options['cache_driver']);
        }
        if (! empty($options['queue_driver'])) {
            $command .= ' --queue-driver='.escapeshellarg($options['queue_driver']);
        }

        $command .= ' --json';

        $result = $this->command->executeCommand($environment, $command);

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'slug' => $result['data']['slug'] ?? $result['data']['project_slug'] ?? Str::slug($options['name']),
                    'status' => $result['data']['status'] ?? 'provisioning',
                    'message' => $result['data']['message'] ?? 'Project creation started',
                ],
            ];
        }

        return $result;
    }

    /**
     * Create project via HTTP API (for remote environments).
     */
    protected function createProjectViaHttp(Environment $environment, array $options): array
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
    public function rebuild(Environment $environment, string $project): array
    {
        if ($environment->is_local) {
            $escapedProject = escapeshellarg($project);

            return $this->command->executeCommand($environment, "project:update --project={$escapedProject} --no-git --json");
        }

        return $this->connector->sendRequest($environment, new RebuildProjectRequest($project));
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
     * Delete a project via the CLI (uses DeletionPipeline for proper cleanup).
     */
    public function deleteProject(Environment $environment, string $slug, bool $force = false, bool $keepDb = false): array
    {
        if (! $environment->is_local) {
            return $this->connector->sendRequest($environment, new DeleteProjectRequest($slug, $keepDb));
        }

        // For local environments, use the CLI command which runs the DeletionPipeline
        $escapedSlug = escapeshellarg($slug);
        $command = "project:delete {$escapedSlug} --force";

        if ($keepDb) {
            $command .= ' --keep-db';
        }

        $command .= ' --json';

        return $this->command->executeCommand($environment, $command);
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
