<?php

namespace App\Console\Commands;

use App\Services\CaddyfileGenerator;
use App\Services\MacBrewService;
use App\Services\MacHorizonService;
use App\Services\MacPhpFpmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class MigrateToFpmCommand extends Command
{
    protected $signature = 'migrate:to-fpm
        {--force : Skip confirmation prompts}';

    protected $description = 'Migrate from FrankenPHP containers to host PHP-FPM + Caddy architecture';

    public function handle(
        MacPhpFpmService $phpFpmService,
        MacBrewService $brewService,
        CaddyfileGenerator $caddyfileGenerator,
        MacHorizonService $horizonService
    ): int {
        $this->info('🚀 Migrating to PHP-FPM architecture...');
        $this->newLine();

        // Step 1: Check prerequisites
        $this->info('Step 1/7: Checking prerequisites...');
        if (! $this->checkPrerequisites($phpFpmService, $brewService)) {
            return Command::FAILURE;
        }

        // Step 2: Confirmation
        if (! $this->option('force')) {
            if (! $this->confirm('This will stop FrankenPHP containers and switch to host PHP-FPM. Continue?')) {
                $this->info('Migration cancelled.');

                return Command::SUCCESS;
            }
        }

        // Step 3: Stop FrankenPHP containers
        $this->info('Step 2/7: Stopping FrankenPHP containers...');
        $this->stopFrankenPhpContainers();

        // Step 4: Install PHP-FPM pool configs
        $this->info('Step 3/7: Installing PHP-FPM pool configs...');
        $versions = $phpFpmService->detectInstalledPhpVersions();
        foreach ($versions as $version) {
            if ($phpFpmService->installPoolConfig($version)) {
                $this->line("  ✓ Installed pool config for PHP {$version}");
            } else {
                $this->warn("  ⚠ Failed to install pool config for PHP {$version}");
            }
        }

        // Step 5: Start PHP-FPM services
        $this->info('Step 4/7: Starting PHP-FPM services...');
        foreach ($versions as $version) {
            if ($brewService->restartPhpFpm($version)) {
                $this->line("  ✓ Started PHP-FPM {$version}");
            } else {
                $this->warn("  ⚠ Failed to start PHP-FPM {$version}");
            }
        }

        // Step 6: Generate and install host Caddyfile
        $this->info('Step 5/7: Configuring Caddy...');
        $sites = $this->getSites();
        $defaultPhp = $versions[count($versions) - 1] ?? '8.4'; // Latest version as default
        $caddyContent = $caddyfileGenerator->generateHostCaddyfile($sites, $defaultPhp);

        if ($caddyfileGenerator->writeHostCaddyfile($caddyContent)) {
            $this->line('  ✓ Generated host Caddyfile');
        } else {
            $this->error('  ✗ Failed to write Caddyfile');

            return Command::FAILURE;
        }

        if ($caddyfileGenerator->setupSystemCaddyfile()) {
            $this->line('  ✓ Configured system Caddyfile');
        } else {
            $this->warn('  ⚠ Could not configure system Caddyfile (may need sudo)');
        }

        if ($brewService->restartCaddy()) {
            $this->line('  ✓ Restarted Caddy');
        } else {
            $this->warn('  ⚠ Failed to restart Caddy');
        }

        // Step 7: Install Horizon launchd service
        $this->info('Step 6/7: Installing Horizon service...');
        if ($horizonService->install($defaultPhp)) {
            $this->line('  ✓ Installed Horizon launchd service');
        } else {
            $this->warn('  ⚠ Failed to install Horizon service');
        }

        // Step 8: Remove old FrankenPHP containers
        $this->info('Step 7/7: Cleaning up old containers...');
        $this->removeFrankenPhpContainers();

        $this->newLine();
        $this->info('✅ Migration complete!');
        $this->newLine();
        $this->line('Services are now running on the host:');
        $this->line('  • PHP-FPM: brew services (sockets at ~/.config/launchpad/php/)');
        $this->line('  • Caddy: brew services (config at ~/.config/launchpad/caddy/Caddyfile)');
        $this->line('  • Horizon: launchd (logs at ~/.config/launchpad/logs/horizon.log)');
        $this->newLine();
        $this->line('Run `launchpad status` to check service status.');

        return Command::SUCCESS;
    }

    protected function checkPrerequisites(MacPhpFpmService $phpFpmService, MacBrewService $brewService): bool
    {
        $errors = [];

        // Check Homebrew
        $brewCheck = Process::run('command -v brew');
        if (! $brewCheck->successful()) {
            $errors[] = 'Homebrew is not installed. Install from https://brew.sh';
        }

        // Check PHP versions
        $versions = $phpFpmService->detectInstalledPhpVersions();
        if (empty($versions)) {
            $errors[] = 'No PHP versions found. Install with: brew install shivammathur/php/php@8.4';
        } else {
            $this->line('  ✓ Found PHP versions: '.implode(', ', $versions));
        }

        // Check Caddy
        $caddyCheck = Process::run('command -v caddy');
        if (! $caddyCheck->successful()) {
            $errors[] = 'Caddy is not installed. Install with: brew install caddy';
        } else {
            $this->line('  ✓ Caddy is installed');
        }

        // Check Docker
        $dockerCheck = Process::run('docker info 2>/dev/null');
        if (! $dockerCheck->successful()) {
            $errors[] = 'Docker is not running. Start Docker Desktop.';
        } else {
            $this->line('  ✓ Docker is running');
        }

        if (! empty($errors)) {
            $this->newLine();
            $this->error('Prerequisites not met:');
            foreach ($errors as $error) {
                $this->line("  ✗ {$error}");
            }

            return false;
        }

        return true;
    }

    protected function stopFrankenPhpContainers(): void
    {
        $containers = ['launchpad-php-83', 'launchpad-php-84', 'launchpad-php-85', 'launchpad-caddy'];

        foreach ($containers as $container) {
            $result = Process::run("docker stop {$container} 2>/dev/null");
            if ($result->successful()) {
                $this->line("  ✓ Stopped {$container}");
            }
        }
    }

    protected function removeFrankenPhpContainers(): void
    {
        $containers = ['launchpad-php-83', 'launchpad-php-84', 'launchpad-php-85', 'launchpad-caddy'];

        foreach ($containers as $container) {
            $result = Process::run("docker rm {$container} 2>/dev/null");
            if ($result->successful()) {
                $this->line("  ✓ Removed {$container}");
            }
        }
    }

    /**
     * Get sites from config (placeholder - should integrate with SiteScanner).
     *
     * @return array<string, array{domain: string, path: string, php_version: string}>
     */
    protected function getSites(): array
    {
        // For now, return empty array - the actual site scanning should be
        // integrated with the existing SiteScanner service when available
        return [];
    }
}
