<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\ProjectScanner;
use LaravelZero\Framework\Commands\Command;

final class ProjectListCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'project:list {--json : Output as JSON}';

    protected $description = 'List ALL directories in scan paths as projects';

    public function handle(ProjectScanner $projectScanner, ConfigManager $configManager): int
    {
        $projects = $projectScanner->scan();
        $tld = $configManager->getTld();
        $defaultPhp = $configManager->getDefaultPhpVersion();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'projects' => $projects,
                'count' => count($projects),
                'tld' => $tld,
                'default_php_version' => $defaultPhp,
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
            }

            $hasPublic = $project['has_public_folder'] ? 'Yes' : 'No';
            $domain = $project['domain'] ?? '-';

            $tableData[] = [
                $project['name'],
                $hasPublic,
                $domain,
                $phpDisplay,
            ];
        }

        $this->table(['Name', 'Has Public', 'Domain', 'PHP'], $tableData);

        $this->newLine();
        $this->line("TLD: {$tld}");
        $this->line("Default PHP: {$defaultPhp}");
        $this->line('Total: '.count($projects).' projects');

        return self::SUCCESS;
    }
}
