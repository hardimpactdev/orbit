<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class WebInstallCommand extends Command
{
    protected $signature = 'web:install {--force : Overwrite existing installation} {--dry-run : Show what would be done without making changes}';

    protected $description = 'Install or update the companion web app from bundle';

    public function handle(ConfigManager $configManager): int
    {
        $bundlePath = base_path('stubs/orbit-web-bundle.tar.gz');
        $destPath = $configManager->getWebAppPath();

        if (! File::exists($bundlePath)) {
            $this->error('Web app bundle not found at: '.$bundlePath);
            $this->error('This CLI version may not include the web app bundle.');

            return self::FAILURE;
        }

        // Check if already installed
        if (File::isDirectory($destPath) && ! $this->option('force') && ! $this->option('dry-run')) {
            $this->info('Web app already installed. Use --force to reinstall.');

            return self::SUCCESS;
        }

        $this->info('Installing companion web app from bundle...');

        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Would extract bundle to: '.$destPath);
            $this->info('[DRY RUN] Would set permissions: chmod 755 for directories and 644 for files');
            $this->info('[DRY RUN] Would generate environment file');
            $this->info('[DRY RUN] Would run database migrations');
            $this->info('[DRY RUN] Would update Caddy configuration');

            return self::SUCCESS;
        }

        // Extract bundle
        $this->task('Extracting web app bundle', fn () => $this->extractBundle($bundlePath, $destPath));

        // Set permissions
        $this->task('Setting file permissions', function () use ($destPath) {
            $this->setPermissions($destPath);

            return true;
        });

        // Generate .env file
        $this->task('Generating environment file', function () use ($configManager) {
            $this->generateWebAppEnv($configManager);

            return true;
        });

        // Run database migrations (using orbit-cli's migrations, shared database)
        $this->task('Running database migrations', fn () => Artisan::call('db:migrate') === 0);

        // Regenerate Caddyfile
        $this->task('Updating Caddy configuration', function () {
            $result = Process::run('orbit caddy:generate 2>/dev/null');

            return $result->successful();
        });

        $this->newLine();
        $this->info('Web app installed successfully!');
        $this->info('');
        $this->info('To complete setup:');
        $this->info('  1. Restart Orbit: orbit restart');
        $tld = $configManager->getTld();
        $this->info("  2. Access at: https://orbit.{$tld}");

        return self::SUCCESS;
    }

    protected function extractBundle(string $bundlePath, string $destination): bool
    {
        if (File::isDirectory($destination) && $this->option('force')) {
            File::deleteDirectory($destination);
        }

        File::ensureDirectoryExists($destination);

        // When running from a PHAR, tar can't read phar:// paths directly.
        // We need to copy the bundle to a temp file first.
        $tempBundle = null;
        if (str_starts_with($bundlePath, 'phar://')) {
            $tempBundle = tempnam(sys_get_temp_dir(), 'orbit-web-bundle-');
            if (! copy($bundlePath, $tempBundle)) {
                return false;
            }
            $bundlePath = $tempBundle;
        }

        try {
            // Using tar command for better reliability and performance
            $result = Process::run("tar -xzf {$bundlePath} -C {$destination}");

            return $result->successful();
        } finally {
            // Clean up temp file
            if ($tempBundle && file_exists($tempBundle)) {
                @unlink($tempBundle);
            }
        }
    }

    protected function setPermissions(string $path): void
    {
        // Set directory permissions to 755
        $process = Process::run("find {$path} -type d -exec chmod 755 {} +");

        // Set file permissions to 644
        $process = Process::run("find {$path} -type f -exec chmod 644 {} +");

        // Ensure artisan is executable
        if (File::exists("{$path}/artisan")) {
            chmod("{$path}/artisan", 0755);
        }

        // Ensure storage and bootstrap/cache are writable (775)
        $writableDirs = [
            "{$path}/storage",
            "{$path}/bootstrap/cache",
        ];

        foreach ($writableDirs as $dir) {
            if (File::isDirectory($dir)) {
                Process::run("chmod -R 775 {$dir}");
            }
        }
    }

    protected function generateWebAppEnv(ConfigManager $configManager): void
    {
        $webAppPath = $configManager->getWebAppPath();
        $tld = $configManager->getTld();
        $reverbConfig = $configManager->getReverbConfig();

        $appKey = null;
        if (File::exists("{$webAppPath}/.env")) {
            $envContent = File::get("{$webAppPath}/.env");
            if (preg_match('/^APP_KEY=(.+)$/m', $envContent, $matches)) {
                $appKey = trim($matches[1]);
            }
        }
        $appKey = $appKey ?: 'base64:'.base64_encode(random_bytes(32));

        // Get the installed CLI path
        $cliPath = getenv('HOME').'/.local/bin/orbit';

        $env = <<<ENV
APP_NAME=Orbit
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL=https://orbit.{$tld}

LOG_CHANNEL=single
LOG_LEVEL=error

# CLI Mode configuration
ORBIT_MODE=cli
DB_CONNECTION=sqlite
DB_DATABASE={$configManager->getConfigPath()}/database.sqlite

# Orbit CLI path
ORBIT_CLI_PATH={$cliPath}

# Redis
REDIS_CLIENT=phpredis
REDIS_HOST=localhost
REDIS_PORT=6379

# Queue via Redis
QUEUE_CONNECTION=redis

# Cache and sessions via Redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Broadcasting via Reverb
BROADCAST_CONNECTION=reverb
REVERB_APP_ID={$reverbConfig['app_id']}
REVERB_APP_KEY={$reverbConfig['app_key']}
REVERB_APP_SECRET={$reverbConfig['app_secret']}
REVERB_HOST={$reverbConfig['host']}
REVERB_PORT={$reverbConfig['port']}
REVERB_SCHEME=https
ENV;

        File::put("{$webAppPath}/.env", $env);
    }
}
