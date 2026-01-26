<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\File;

final readonly class CopyConfigurationFiles
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $stubsPath = base_path('stubs');
        $configPath = $context->configDir;

        if (! File::isDirectory($stubsPath)) {
            $logger->warn('Stubs directory not found - skipping configuration copy');

            return StepResult::success();
        }

        // Copy all stub directories
        $directories = ['php', 'caddy', 'dns', 'postgres', 'redis', 'mailpit', 'horizon', 'reverb'];

        foreach ($directories as $dir) {
            $sourcePath = "{$stubsPath}/{$dir}";
            $destPath = "{$configPath}/{$dir}";

            if (File::isDirectory($sourcePath)) {
                $this->copyDirectory($sourcePath, $destPath);
            }
        }

        // Copy config.json if it doesn't exist
        $configJsonDest = "{$configPath}/config.json";
        if (! File::exists($configJsonDest) && File::exists("{$stubsPath}/config.json")) {
            File::copy("{$stubsPath}/config.json", $configJsonDest);
        }

        // Copy CLAUDE.md
        if (File::exists("{$stubsPath}/CLAUDE.md")) {
            File::copy("{$stubsPath}/CLAUDE.md", "{$configPath}/CLAUDE.md");
        }

        $logger->success('Configuration files deployed');

        return StepResult::success();
    }

    private function copyDirectory(string $source, string $destination): void
    {
        File::ensureDirectoryExists($destination);

        foreach (File::files($source) as $file) {
            $destPath = "{$destination}/{$file->getFilename()}";

            // Don't overwrite existing docker-compose.yml files
            if ($file->getFilename() === 'docker-compose.yml' && File::exists($destPath)) {
                continue;
            }

            File::copy($file->getPathname(), $destPath);
        }
    }
}
