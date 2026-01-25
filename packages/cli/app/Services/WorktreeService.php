<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorktreeService
{
    protected string $worktreesPath;

    public function __construct(
        protected ConfigManager $configManager,
        protected ProjectScanner $projectScanner
    ) {
        $this->worktreesPath = $this->configManager->getConfigPath().'/worktrees.json';
    }

    /**
     * Get all worktrees for all sites, auto-linking new ones.
     */
    public function getAllWorktrees(): array
    {
        $projects = $this->projectScanner->scan();
        $tld = $this->configManager->getTld();
        $linkedWorktrees = $this->loadLinkedWorktrees();
        $allWorktrees = [];
        $newWorktreesDetected = false;

        foreach ($projects as $project) {
            $projectWorktrees = $this->detectWorktrees($project['path']);

            foreach ($projectWorktrees as $worktree) {
                // Check if already linked
                $isLinked = isset($linkedWorktrees[$project['name']][$worktree['name']]);

                // Auto-link new worktrees
                if (! $isLinked) {
                    $this->linkWorktree($project['name'], $worktree['path'], $worktree['name']);
                    $linkedWorktrees = $this->loadLinkedWorktrees(); // Reload
                    $newWorktreesDetected = true;
                }

                $domain = "{$worktree['name']}.{$project['name']}.{$tld}";

                $allWorktrees[] = [
                    'project' => $project['name'],
                    'name' => $worktree['name'],
                    'branch' => $worktree['branch'] ?? null,
                    'path' => $worktree['path'],
                    'domain' => $domain,
                    'php_version' => $project['php_version'],
                    'secure' => true,
                ];
            }
        }

        // If new worktrees were detected, regenerate Caddy config
        if ($newWorktreesDetected) {
            $this->regenerateCaddyConfig();
        }

        return $allWorktrees;
    }

    /**
     * Get worktrees for a specific site.
     */
    public function getSiteWorktrees(string $siteName): array
    {
        $allWorktrees = $this->getAllWorktrees();

        return array_values(array_filter(
            $allWorktrees,
            fn ($wt) => $wt['site'] === $siteName
        ));
    }

    /**
     * Detect git worktrees for a given site path.
     */
    public function detectWorktrees(string $sitePath): array
    {
        $result = Process::path($sitePath)->run('git worktree list --porcelain 2>/dev/null');

        if (! $result->successful()) {
            return [];
        }

        $output = $result->output();
        $worktrees = [];
        $current = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '') {
                if (! empty($current) && isset($current['path'])) {
                    $worktrees[] = $current;
                }
                $current = [];

                continue;
            }

            if (str_starts_with($line, 'worktree ')) {
                $current['path'] = substr($line, 9);
            } elseif (str_starts_with($line, 'branch ')) {
                $branch = substr($line, 7);
                // Remove refs/heads/ prefix
                if (str_starts_with($branch, 'refs/heads/')) {
                    $branch = substr($branch, 11);
                }
                $current['branch'] = $branch;
            } elseif (str_starts_with($line, 'HEAD ')) {
                $current['head'] = substr($line, 5);
            }
        }

        // Don't forget the last worktree
        if (! empty($current) && isset($current['path'])) {
            $worktrees[] = $current;
        }

        // Filter out the main worktree (same as site path) and extract names
        $filteredWorktrees = [];
        foreach ($worktrees as $wt) {
            // Skip the main worktree
            if (realpath($wt['path']) === realpath($sitePath)) {
                continue;
            }

            // Extract worktree name from path
            // e.g., /var/tmp/vibe-kanban/worktrees/0d16-update-homepage/platform11-2026
            // -> 0d16-update-homepage
            $pathParts = explode('/', rtrim($wt['path'], '/'));
            $projectName = array_pop($pathParts); // platform11-2026
            $worktreeName = array_pop($pathParts); // 0d16-update-homepage

            // Fallback: use branch name if path parsing fails
            if (empty($worktreeName) || $worktreeName === 'worktrees') {
                $worktreeName = $wt['branch'] ?? basename($wt['path']);
                // Clean branch name (remove vk/ prefix)
                if (str_starts_with($worktreeName, 'vk/')) {
                    $worktreeName = substr($worktreeName, 3);
                }
            }

            $filteredWorktrees[] = [
                'name' => $worktreeName,
                'path' => $wt['path'],
                'branch' => $wt['branch'] ?? null,
            ];
        }

        return $filteredWorktrees;
    }

    /**
     * Link a worktree to a site.
     */
    public function linkWorktree(string $siteName, string $path, ?string $name = null): void
    {
        $linkedWorktrees = $this->loadLinkedWorktrees();

        if ($name === null) {
            // Extract name from path
            $pathParts = explode('/', rtrim($path, '/'));
            array_pop($pathParts); // Remove project name
            $name = array_pop($pathParts); // Get worktree name
        }

        if (! isset($linkedWorktrees[$siteName])) {
            $linkedWorktrees[$siteName] = [];
        }

        $linkedWorktrees[$siteName][$name] = [
            'path' => $path,
            'linked_at' => date('c'),
        ];

        $this->saveLinkedWorktrees($linkedWorktrees);

        // Try to update the worktree's .env file (best effort, may fail due to permissions)
        $this->updateWorktreeEnv($siteName, $name, $path);
    }

    /**
     * Unlink a worktree from a site.
     */
    public function unlinkWorktree(string $siteName, string $worktreeName): bool
    {
        $linkedWorktrees = $this->loadLinkedWorktrees();

        if (! isset($linkedWorktrees[$siteName][$worktreeName])) {
            return false;
        }

        unset($linkedWorktrees[$siteName][$worktreeName]);

        // Clean up empty site entries
        if (empty($linkedWorktrees[$siteName])) {
            unset($linkedWorktrees[$siteName]);
        }

        $this->saveLinkedWorktrees($linkedWorktrees);
        $this->regenerateCaddyConfig();

        return true;
    }

    /**
     * Update a worktree's .env file with correct APP_URL.
     * This is best-effort - if we don't have write permissions, we silently skip.
     */
    public function updateWorktreeEnv(string $siteName, string $worktreeName, string $path): bool
    {
        $tld = $this->configManager->getTld();
        $domain = "{$worktreeName}.{$siteName}.{$tld}";
        $appUrl = "https://{$domain}";

        $envPath = rtrim($path, '/').'/.env';

        if (! File::exists($envPath)) {
            return false;
        }

        // Check if we can write to the file
        if (! is_writable($envPath)) {
            return false;
        }

        try {
            $content = File::get($envPath);

            // Update APP_URL
            if (preg_match('/^APP_URL=.*/m', $content)) {
                $content = preg_replace('/^APP_URL=.*/m', "APP_URL={$appUrl}", $content);
            } else {
                $content .= "\nAPP_URL={$appUrl}\n";
            }

            // Optionally set a unique database name based on worktree
            $dbName = str_replace('-', '_', $siteName.'_'.$worktreeName);
            if (preg_match('/^DB_DATABASE=.*/m', (string) $content)) {
                $content = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$dbName}", (string) $content);
            }

            File::put($envPath, $content);

            return true;
        } catch (\Exception) {
            // Silently fail - permission issues are expected for some worktrees
            return false;
        }
    }

    /**
     * Load linked worktrees from storage.
     */
    protected function loadLinkedWorktrees(): array
    {
        if (! File::exists($this->worktreesPath)) {
            return [];
        }

        return json_decode(File::get($this->worktreesPath), true) ?? [];
    }

    /**
     * Save linked worktrees to storage.
     */
    protected function saveLinkedWorktrees(array $worktrees): void
    {
        File::put(
            $this->worktreesPath,
            json_encode($worktrees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Get the linked worktrees for Caddy config generation.
     */
    public function getLinkedWorktreesForCaddy(): array
    {
        $linkedWorktrees = $this->loadLinkedWorktrees();
        $projects = $this->projectScanner->scan();
        $tld = $this->configManager->getTld();
        $result = [];

        foreach ($linkedWorktrees as $projectName => $worktrees) {
            // Find the project to get PHP version
            $project = null;
            foreach ($projects as $p) {
                if ($p['name'] === $projectName) {
                    $project = $p;
                    break;
                }
            }

            if (! $project) {
                continue;
            }

            foreach ($worktrees as $name => $data) {
                $result[] = [
                    'project' => $projectName,
                    'name' => $name,
                    'path' => $data['path'],
                    'domain' => "{$name}.{$projectName}.{$tld}",
                    'php_version' => $project['php_version'],
                ];
            }
        }

        return $result;
    }

    /**
     * Trigger Caddy config regeneration.
     */
    protected function regenerateCaddyConfig(): void
    {
        // We need to regenerate the Caddyfile - the CaddyfileGenerator
        // will call getLinkedWorktreesForCaddy() to include worktrees
        $caddyGenerator = app(CaddyfileGenerator::class);
        $caddyGenerator->generate();
        $caddyGenerator->reload();
        $caddyGenerator->reloadPhp();
    }

    /**
     * Refresh - re-scan and auto-link new worktrees.
     */
    public function refresh(): array
    {
        $worktrees = $this->getAllWorktrees();

        // Always regenerate Caddy config in case worktrees exist but config is stale
        if (count($worktrees) > 0) {
            $this->regenerateCaddyConfig();
        }

        return $worktrees;
    }

    /**
     * Clean up orphaned worktree entries (worktrees that no longer exist).
     */
    public function cleanupOrphaned(): int
    {
        $linkedWorktrees = $this->loadLinkedWorktrees();
        $removed = 0;

        foreach ($linkedWorktrees as $siteName => $worktrees) {
            foreach ($worktrees as $name => $data) {
                if (! File::isDirectory($data['path'])) {
                    unset($linkedWorktrees[$siteName][$name]);
                    $removed++;
                }
            }

            if (empty($linkedWorktrees[$siteName])) {
                unset($linkedWorktrees[$siteName]);
            }
        }

        if ($removed > 0) {
            $this->saveLinkedWorktrees($linkedWorktrees);
            $this->regenerateCaddyConfig();
        }

        return $removed;
    }
}
