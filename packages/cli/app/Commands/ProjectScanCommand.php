<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class ProjectScanCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:scan
        {path? : Specific path to scan (defaults to configured paths)}
        {--depth=2 : Maximum directory depth to scan}
        {--json : Output as JSON}';

    protected $description = 'Scan for existing projects (git repositories) in configured paths';

    public function handle(ConfigManager $config): int
    {
        /** @var string|null $specificPath */
        $specificPath = $this->argument('path');
        $depth = (int) $this->option('depth');

        $pathsToScan = [];

        if ($specificPath) {
            $pathsToScan[] = $this->expandPath($specificPath);
        } else {
            $configPaths = $config->get('paths', []);
            foreach ($configPaths as $p) {
                $pathsToScan[] = $this->expandPath($p);
            }
        }

        if (empty($pathsToScan)) {
            return $this->failWithMessage('No paths configured to scan');
        }

        $projects = [];

        foreach ($pathsToScan as $basePath) {
            if (! is_dir($basePath)) {
                continue;
            }

            $found = $this->scanDirectory($basePath, $depth);
            $projects = array_merge($projects, $found);
        }

        // Sort by name
        usort($projects, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        if ($this->wantsJson()) {
            $this->outputJsonSuccess([
                'projects' => $projects,
                'count' => count($projects),
            ]);
        } else {
            if (empty($projects)) {
                $this->info('No projects found.');
            } else {
                $this->info('Found '.count($projects).' project(s):');
                $this->newLine();

                foreach ($projects as $project) {
                    $this->line("  <info>{$project['name']}</info>");
                    $this->line("    Path: {$project['path']}");
                    if ($project['github_url']) {
                        $this->line("    GitHub: {$project['github_url']}");
                    }
                    $this->newLine();
                }
            }
        }

        return ExitCode::Success->value;
    }

    private function scanDirectory(string $basePath, int $maxDepth, int $currentDepth = 0): array
    {
        $projects = [];

        if ($currentDepth > $maxDepth) {
            return $projects;
        }

        $entries = @scandir($basePath);
        if ($entries === false) {
            return $projects;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = "{$basePath}/{$entry}";

            if (! is_dir($fullPath)) {
                continue;
            }

            // Check if this is a git repository
            if (is_dir("{$fullPath}/.git")) {
                $project = $this->extractProjectInfo($fullPath);
                if ($project) {
                    $projects[] = $project;
                }

                // Don't recurse into git repos
                continue;
            }

            // Recurse into subdirectories (but skip common non-project dirs)
            if (! in_array($entry, ['node_modules', 'vendor', '.cache', '.config', '.local'])) {
                $subProjects = $this->scanDirectory($fullPath, $maxDepth, $currentDepth + 1);
                $projects = array_merge($projects, $subProjects);
            }
        }

        return $projects;
    }

    private function extractProjectInfo(string $path): array
    {
        $name = basename($path);
        $githubUrl = null;

        // Try to get GitHub URL from git remote
        $result = Process::path($path)->timeout(5)->run('git remote get-url origin 2>/dev/null');
        if ($result->successful()) {
            $remoteUrl = trim($result->output());
            $githubUrl = $this->normalizeGithubUrl($remoteUrl);
        }

        // Detect project type
        $type = 'unknown';
        if (file_exists("{$path}/artisan")) {
            $type = 'laravel';
        } elseif (file_exists("{$path}/composer.json")) {
            $type = 'php';
        } elseif (file_exists("{$path}/package.json")) {
            $type = 'node';
        }

        return [
            'name' => $name,
            'path' => $path,
            'github_url' => $githubUrl,
            'type' => $type,
        ];
    }

    private function normalizeGithubUrl(?string $remoteUrl): ?string
    {
        if (! $remoteUrl) {
            return null;
        }

        // Convert SSH URL to HTTPS
        if (preg_match('/^git@github\.com:(.+)\.git$/', $remoteUrl, $matches)) {
            return 'https://github.com/'.$matches[1];
        }

        // Clean up HTTPS URL
        if (preg_match('/^https:\/\/github\.com\/(.+?)(\.git)?$/', $remoteUrl, $matches)) {
            return 'https://github.com/'.rtrim($matches[1], '.git');
        }

        return null;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'].substr($path, 1);
        }

        return $path;
    }

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            $this->outputJsonError($message);
        } else {
            $this->error($message);
        }

        return ExitCode::GeneralError->value;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json') || ! $this->input->isInteractive();
    }
}
