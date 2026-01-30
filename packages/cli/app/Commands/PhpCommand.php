<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Contracts\CaddyfileGeneratorInterface;
use App\Enums\ExitCode;
use App\Services\ConfigManager;
use App\Services\DatabaseService;
use App\Services\ProjectScanner;
use LaravelZero\Framework\Commands\Command;

class PhpCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'php
        {project : The project name to configure}
        {version? : The PHP version to use (8.3, 8.4, 8.5)}
        {--reset : Reset to default PHP version}
        {--json : Output as JSON}';

    protected $description = 'Set PHP version for a project';

    protected array $validVersions = ['8.3', '8.4', '8.5'];

    public function handle(
        ConfigManager $configManager,
        ProjectScanner $projectScanner,
        CaddyfileGeneratorInterface $caddyfileGenerator,
        DatabaseService $databaseService
    ): int {
        $project = $this->argument('project');
        $version = $this->argument('version');
        $reset = $this->option('reset');

        // Verify project exists (now includes all directories)
        $projectInfo = $projectScanner->findProject($project);
        if (! $projectInfo) {
            if ($this->wantsJson()) {
                return $this->outputJsonError(
                    "Project '{$project}' not found.",
                    ExitCode::InvalidArguments->value
                );
            }
            $this->error("Project '{$project}' not found.");

            return ExitCode::InvalidArguments->value;
        }

        if ($reset) {
            // Remove from database
            $databaseService->removeSiteOverride($project);
            // Also remove from config (legacy cleanup)
            $configManager->removeSiteOverride($project);

            $newVersion = $configManager->getDefaultPhpVersion();

            // Only regenerate Caddyfile if project has public folder
            if ($projectInfo['has_public_folder']) {
                $this->regenerateAndReload($caddyfileGenerator);
            }

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'project' => $project,
                    'php_version' => $newVersion,
                    'action' => 'reset',
                    'reloaded' => $projectInfo['has_public_folder'],
                ]);
            }

            $this->info("Reset {$project} to default PHP version ({$newVersion})");

            return self::SUCCESS;
        }

        if (! $version) {
            if ($this->wantsJson()) {
                return $this->outputJsonError(
                    'Please provide a PHP version or use --reset',
                    ExitCode::InvalidArguments->value
                );
            }
            $this->error('Please provide a PHP version or use --reset');

            return ExitCode::InvalidArguments->value;
        }

        if (! in_array($version, $this->validVersions)) {
            $message = 'Invalid PHP version. Valid versions: '.implode(', ', $this->validVersions);
            if ($this->wantsJson()) {
                return $this->outputJsonError($message, ExitCode::InvalidArguments->value, [
                    'valid_versions' => $this->validVersions,
                ]);
            }
            $this->error($message);

            return ExitCode::InvalidArguments->value;
        }

        // Save to database (new way)
        $databaseService->setSitePhpVersion($project, $projectInfo['path'], $version);

        // Only regenerate Caddyfile if project has public folder
        $reloaded = false;
        if ($projectInfo['has_public_folder']) {
            $reloaded = $this->regenerateAndReload($caddyfileGenerator);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'project' => $project,
                'php_version' => $version,
                'action' => 'set',
                'reloaded' => $reloaded,
            ]);
        }

        $this->info("Set {$project} to PHP {$version}");

        if ($reloaded) {
            $this->info('Caddy reloaded');
        } elseif ($projectInfo['has_public_folder']) {
            $this->warn('Could not reload Caddy. You may need to restart Orbit.');
        }

        return self::SUCCESS;
    }

    private function regenerateAndReload(CaddyfileGeneratorInterface $caddyfileGenerator): bool
    {
        if (! $this->wantsJson()) {
            $this->task('Regenerating Caddyfile', function () use ($caddyfileGenerator) {
                $caddyfileGenerator->generate();

                return true;
            });
        } else {
            $caddyfileGenerator->generate();
        }

        return $caddyfileGenerator->reload();
    }
}
