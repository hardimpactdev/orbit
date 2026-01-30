<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

class ProjectScanner
{
    public function __construct(
        protected ConfigManager $configManager,
        protected DatabaseService $databaseService
    ) {}

    /**
     * Scan all directories in configured paths.
     * Returns ALL directories as projects, with has_public_folder flag.
     */
    public function scan(): array
    {
        $projects = [];
        $paths = $this->configManager->getPaths();
        $tld = $this->configManager->getTld();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $projectOverrides = $this->configManager->getSiteOverrides(); // Config method still uses site naming
        $seenNames = [];

        // First, process custom projects with explicit paths defined in config
        foreach ($projectOverrides as $name => $override) {
            if (isset($override['path'])) {
                $customPath = $this->expandPath($override['path']);

                if (File::isDirectory($customPath)) {
                    $seenNames[$name] = true;
                    $hasPublicFolder = File::isDirectory($customPath.'/public');
                    $phpVersion = $this->detectPhpVersion($customPath, $name, $defaultPhp);

                    $project = [
                        'name' => $name,
                        'display_name' => $this->getDisplayName($customPath, $name),
                        'github_repo' => $this->getGitHubRepo($customPath),
                        'project_type' => $this->getProjectType($customPath),
                        'path' => $customPath,
                        'has_public_folder' => $hasPublicFolder,
                        'php_version' => $phpVersion,
                        'has_custom_php' => $phpVersion !== $defaultPhp,
                    ];

                    // Only add URL info if has public folder
                    if ($hasPublicFolder) {
                        $project['domain'] = "{$name}.{$tld}";
                        $project['url'] = "https://{$name}.{$tld}";
                        $project['secure'] = true;
                    }

                    $this->databaseService->setSitePath($name, $project['path']); // DB method still uses site naming
                    $projects[] = $project;
                }
            }
        }

        // Then scan configured paths for auto-discovered projects (ALL directories)
        foreach ($paths as $path) {
            $expandedPath = $this->expandPath($path);

            if (! File::isDirectory($expandedPath)) {
                continue;
            }

            $directories = File::directories($expandedPath);

            foreach ($directories as $directory) {
                $name = basename((string) $directory);

                // Skip if we've already seen this name (custom projects take precedence)
                if (isset($seenNames[$name])) {
                    continue;
                }

                $seenNames[$name] = true;

                $hasPublicFolder = File::isDirectory($directory.'/public');
                $phpVersion = $this->detectPhpVersion($directory, $name, $defaultPhp);

                $project = [
                    'name' => $name,
                    'display_name' => $this->getDisplayName($directory, $name),
                    'github_repo' => $this->getGitHubRepo($directory),
                    'project_type' => $this->getProjectType($directory),
                    'path' => $directory,
                    'has_public_folder' => $hasPublicFolder,
                    'php_version' => $phpVersion,
                    'has_custom_php' => $phpVersion !== $defaultPhp,
                ];

                // Only add URL info if has public folder
                if ($hasPublicFolder) {
                    $project['domain'] = "{$name}.{$tld}";
                    $project['url'] = "https://{$name}.{$tld}";
                    $project['secure'] = true;
                }

                $this->databaseService->setSitePath($name, $project['path']);
                $projects[] = $project;
            }
        }

        // Clean up orphan projects (in DB but not found on disk)
        $this->cleanupOrphanProjects($seenNames);

        usort($projects, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $projects;
    }

    /**
     * Get only projects with public folder for Caddyfile generation.
     */
    public function scanProjects(): array
    {
        return array_filter($this->scan(), fn ($p) => $p['has_public_folder']);
    }

    protected function detectPhpVersion(string $directory, string $name, string $default): string
    {
        // Check database first (primary source of truth)
        $dbVersion = $this->databaseService->getPhpVersion($name);
        if ($dbVersion !== null && $this->isValidPhpVersion($dbVersion)) {
            return $dbVersion;
        }

        // Fallback: check .php-version file (legacy support)
        $phpVersionFile = $directory.'/.php-version';
        if (File::exists($phpVersionFile)) {
            $version = trim(File::get($phpVersionFile));
            if ($this->isValidPhpVersion($version)) {
                // Migrate to database
                $this->databaseService->setSitePhpVersion($name, $directory, $version);

                return $version;
            }
        }

        // Check config override (legacy, will be migrated)
        $configVersion = $this->configManager->getSitePhpVersion($name);
        if ($configVersion !== null) {
            return $configVersion;
        }

        return $default;
    }

    protected function isValidPhpVersion(string $version): bool
    {
        return in_array($version, ['8.3', '8.4', '8.5']);
    }

    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }

    public function findProject(string $name): ?array
    {
        $projects = $this->scan();

        foreach ($projects as $project) {
            if ($project['name'] === $name) {
                return $project;
            }
        }

        return null;
    }

    /**
     * Find a project's path, using stored path if valid, otherwise rescanning.
     */
    public function findProjectPath(string $slug): ?string
    {
        // Check stored path first (fast path)
        $storedPath = $this->databaseService->getSitePath($slug);

        if ($storedPath && is_dir($storedPath)) {
            return $storedPath;
        }

        // Path missing or invalid - rescan to find new location
        // scan() will update the stored path if found
        $project = $this->findProject($slug);

        return $project['path'] ?? null;
    }

    /**
     * Clean up orphan projects (in DB but not found on disk).
     *
     * Only deletes CLI-created projects (those with NULL environment_id).
     * Projects with environment_id set were created via web UI and may be
     * in provisioning state (directory not yet created).
     *
     * @param  array<string, bool>  $foundProjects
     */
    protected function cleanupOrphanProjects(array $foundProjects): void
    {
        $dbSlugs = $this->databaseService->getAllSiteSlugs();

        foreach ($dbSlugs as $slug) {
            if (! isset($foundProjects[$slug])) {
                // Only delete if it's a CLI-created project (no environment_id)
                // and not currently being provisioned
                $project = \HardImpact\Orbit\Core\Models\Project::where('slug', $slug)->first();
                if ($project && $project->environment_id === null && $project->status === 'active') {
                    $this->databaseService->deleteSite($slug);
                }
            }
        }
    }

    /**
     * Get display name for a project from .env APP_NAME or generate from slug.
     */
    protected function getDisplayName(string $directory, string $slug): string
    {
        // Try to read APP_NAME from .env file
        $envPath = $directory.'/.env';
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            if (preg_match('/^APP_NAME=(.+)$/m', $envContent, $matches)) {
                $appName = trim($matches[1], "\"' ");
                if ($appName) {
                    return $appName;
                }
            }
        }

        // Generate display name from slug: "my-cool-project" -> "My Cool Project"
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Get GitHub repo URL from .git/config if available.
     */
    protected function getGitHubRepo(string $directory): ?string
    {
        $gitConfig = $directory.'/.git/config';
        if (! File::exists($gitConfig)) {
            return null;
        }

        $configContent = File::get($gitConfig);
        // Match git@github.com:owner/repo.git or https://github.com/owner/repo.git
        if (preg_match("/url\s*=\s*(?:git@github\.com:|https:\/\/github\.com\/)([^\/]+\/[^\/\s]+?)(?:\.git)?$/m", $configContent, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect the project type based on file structure.
     */
    protected function getProjectType(string $directory): string
    {
        $hasPublicFolder = File::isDirectory($directory.'/public');
        $hasArtisan = File::exists($directory.'/artisan');
        $composerJson = $directory.'/composer.json';

        if (File::exists($composerJson)) {
            $composer = json_decode(File::get($composerJson), true);

            // Check if it is a Laravel package
            $type = $composer['type'] ?? null;
            if ($type === 'library' || $type === 'laravel-package') {
                return 'laravel-package';
            }

            // Check for package indicators in composer.json
            $extra = $composer['extra'] ?? [];
            if (isset($extra['laravel']['providers']) || isset($extra['laravel']['aliases'])) {
                return 'laravel-package';
            }
        }

        // Laravel web application
        if ($hasPublicFolder && $hasArtisan) {
            return 'laravel-app';
        }

        // Laravel Zero or other CLI app
        if ($hasArtisan) {
            return 'cli';
        }

        // Check for Laravel Zero CLI apps (they don't have artisan but have app/Commands)
        if (File::exists($composerJson)) {
            $composer = json_decode(File::get($composerJson), true);
            if (isset($composer['require']['laravel-zero/framework']) ||
                (File::isDirectory($directory.'/app/Commands') && ! $hasPublicFolder)) {
                return 'cli';
            }
        }

        // Generic PHP project with web interface
        if ($hasPublicFolder) {
            return 'web';
        }

        return 'unknown';
    }
}
