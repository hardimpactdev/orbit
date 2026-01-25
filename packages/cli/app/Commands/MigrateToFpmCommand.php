<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\CaddyfileGenerator;
use App\Services\CaddyManager;
use App\Services\DockerManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use LaravelZero\Framework\Commands\Command;

class MigrateToFpmCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'migrate:to-fpm 
        {--force : Skip confirmation prompts}
        {--keep-containers : Keep old PHP containers after migration}
        {--json : Output results as JSON}';

    protected $description = 'Migrate from FrankenPHP containers to PHP-FPM on host';

    public function handle(
        PhpManager $phpManager,
        CaddyManager $caddyManager,
        CaddyfileGenerator $caddyfileGenerator,
        HorizonManager $horizonManager,
        DockerManager $dockerManager
    ): int {
        // Detect current setup
        $usingFpm = $this->isUsingFpm($phpManager);

        // Check if FrankenPHP containers exist (running OR stopped)
        $usingFrankenPhp = $dockerManager->containerExists('orbit-php-84')
            || $dockerManager->containerExists('orbit-php-83')
            || $dockerManager->containerExists('orbit-php-82')
            || $dockerManager->containerExists('orbit-caddy');

        if ($usingFpm && ! $usingFrankenPhp) {
            return $this->outputResult(['success' => true, 'message' => 'Already using PHP-FPM']);
        }

        if (! $usingFrankenPhp && ! $usingFpm) {
            // Fresh install - set up FPM architecture
            $this->info('Setting up PHP-FPM architecture...');
            // Fall through to FPM setup
        }

        // Confirm migration
        if (! $this->option('force') && ! $this->option('json')) {
            if (! $this->confirm('This will migrate from FrankenPHP to PHP-FPM. Continue?')) {
                return 0;
            }
        }

        $this->info('Migrating from FrankenPHP to PHP-FPM...');

        // 1. Stop current services
        $this->task('Stopping current services', fn () => $this->call('stop') === 0);

        // 2. Install PHP-FPM if not present
        $this->task('Installing PHP-FPM', fn () => $this->ensurePhpInstalled($phpManager));

        // 3. Configure FPM pools
        $this->task('Configuring FPM pools', fn () => $this->configurePools($phpManager));

        // 4. Install host Caddy if not present
        $this->task('Installing Caddy', function () use ($caddyManager) {
            if (! $caddyManager->isInstalled()) {
                return $caddyManager->install();
            }

            return true;
        });

        // 5. Regenerate Caddyfile for FPM sockets
        $this->task('Regenerating Caddyfile', function () use ($caddyfileGenerator) {
            $caddyfileGenerator->generate();

            return true;
        });

        // 6. Reload Caddy with new config
        $this->task('Reloading Caddy', fn () => $caddyManager->reload());

        // 7. Setup Horizon service
        $this->task('Installing Horizon service', fn () => $horizonManager->install());

        // 8. Remove old containers (unless --keep-containers)
        if (! $this->option('keep-containers')) {
            $this->task('Removing old PHP containers', function () use ($dockerManager) {
                $containers = ['orbit-php-82', 'orbit-php-83', 'orbit-php-84', 'orbit-php-85', 'orbit-caddy', 'orbit-horizon'];
                foreach ($containers as $container) {
                    if ($dockerManager->containerExists($container)) {
                        if ($dockerManager->isRunning($container)) {
                            $dockerManager->stop($container);
                        }
                        $dockerManager->removeContainer($container);
                    }
                }

                return true;
            });
        }

        // 9. Start new services
        $this->task('Starting services', fn () => $this->call('start') === 0);

        return $this->outputResult([
            'success' => true,
            'message' => 'Migration complete',
            'data' => [
                'php_fpm_active' => $this->isUsingFpm($phpManager),
                'horizon_active' => $horizonManager->isRunning(),
                'caddy_active' => $caddyManager->isRunning(),
            ],
        ]);
    }

    private function outputResult(array $result): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->info($result['message']);
        }

        return $result['success'] ? 0 : 1;
    }

    private function isUsingFpm(PhpManager $phpManager): bool
    {
        // Check if any FPM socket exists
        $versions = ['8.2', '8.3', '8.4'];
        foreach ($versions as $version) {
            if (file_exists($phpManager->getSocketPath($version))) {
                return true;
            }
        }

        return false;
    }

    private function ensurePhpInstalled(PhpManager $phpManager): bool
    {
        $versions = ['8.3', '8.4'];
        foreach ($versions as $version) {
            if (! $phpManager->isInstalled($version)) {
                if (! $phpManager->install($version)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function configurePools(PhpManager $phpManager): bool
    {
        $versions = ['8.3', '8.4'];
        foreach ($versions as $version) {
            if ($phpManager->isInstalled($version)) {
                $phpManager->configurePool($version);
            }
        }

        return true;
    }
}
