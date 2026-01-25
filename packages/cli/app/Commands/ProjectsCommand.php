<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\ProjectScanner;
use LaravelZero\Framework\Commands\Command;

class ProjectsCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'projects {--json : Output as JSON}';

    protected $description = 'List all projects with their PHP versions';

    public function handle(ProjectScanner $projectScanner, ConfigManager $configManager): int
    {
        $projects = $projectScanner->scan();
        $defaultPhp = $configManager->getDefaultPhpVersion();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'projects' => array_values(array_map(fn ($project) => [
                    'name' => $project['name'],
                    'display_name' => $project['display_name'] ?? ucwords(str_replace(['-', '_'], ' ', $project['name'])),
                    'github_repo' => $project['github_repo'] ?? null,
                    'project_type' => $project['project_type'] ?? 'unknown',
                    'has_public_folder' => $project['has_public_folder'] ?? false,
                    'domain' => $project['domain'] ?? null,
                    'url' => $project['url'] ?? null,
                    'path' => $project['path'],
                    'php_version' => $project['php_version'],
                    'has_custom_php' => $project['has_custom_php'],
                    'secure' => true, // All projects use TLS via Caddy
                ], $projects)),
                'default_php_version' => $defaultPhp,
                'projects_count' => count($projects),
            ]);
        }

        if (empty($projects)) {
            $this->warn('No projects found. Add paths to your config.json file.');

            return self::SUCCESS;
        }

        $this->info('Projects:');
        $this->newLine();

        $tableData = [];
        foreach ($projects as $project) {
            $phpDisplay = $project['php_version'];
            if ($project['has_custom_php']) {
                $phpDisplay .= ' (custom)';
            } else {
                $phpDisplay .= ' (default)';
            }

            $tableData[] = [
                $project['domain'],
                $phpDisplay,
                $project['path'],
            ];
        }

        $this->table(['Domain', 'PHP Version', 'Path'], $tableData);

        $this->newLine();
        $this->line("Default PHP version: {$defaultPhp}");

        return self::SUCCESS;
    }
}
