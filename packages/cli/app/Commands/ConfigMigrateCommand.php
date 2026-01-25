<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\DatabaseService;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

final class ConfigMigrateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'config:migrate {--json : Output as JSON}';

    protected $description = 'Migrate config.json site overrides to SQLite database';

    public function handle(ConfigManager $configManager, DatabaseService $databaseService): int
    {
        $siteOverrides = $configManager->getSiteOverrides();
        $migrated = 0;
        $errors = [];

        if (empty($siteOverrides)) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'migrated' => 0,
                    'message' => 'No site overrides found in config.json',
                ]);
            }
            $this->info('No site overrides found in config.json');

            return self::SUCCESS;
        }

        $paths = $configManager->getPaths();

        foreach ($siteOverrides as $slug => $override) {
            if (isset($override['php_version'])) {
                // Try to find the project path
                $path = $override['path'] ?? $this->findProjectPath($slug, $paths);

                if ($path) {
                    try {
                        $databaseService->setSitePhpVersion($slug, $path, $override['php_version']);
                        $migrated++;

                        if (! $this->wantsJson()) {
                            $this->info("Migrated: {$slug} -> PHP {$override['php_version']}");
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Failed to migrate {$slug}: {$e->getMessage()}";
                    }
                } else {
                    $errors[] = "Could not find path for {$slug}";
                }
            }
        }

        // Clear sites from config.json after migration
        if ($migrated > 0) {
            $configManager->set('sites', []);

            if (! $this->wantsJson()) {
                $this->info('Cleared sites from config.json');
            }
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'migrated' => $migrated,
                'errors' => $errors,
            ]);
        }

        $this->newLine();
        $this->info("Migration complete: {$migrated} sites migrated");

        if (! empty($errors)) {
            $this->newLine();
            $this->warn('Errors:');
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        return self::SUCCESS;
    }

    private function findProjectPath(string $slug, array $paths): ?string
    {
        foreach ($paths as $path) {
            $expandedPath = $this->expandPath($path);
            $projectPath = "{$expandedPath}/{$slug}";

            if (File::isDirectory($projectPath)) {
                return $projectPath;
            }
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
}
