<?php

namespace App\Commands;

use App\Services\ConfigManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class WebInstallCommand extends Command
{
    protected $signature = 'web:install {--force : Overwrite existing installation}';

    protected $description = 'Install or update the companion web app';

    public function handle(ConfigManager $configManager): int
    {
        $sourcePath = base_path('web');
        $destPath = $configManager->getWebAppPath();

        // Check if source exists
        if (! File::isDirectory($sourcePath)) {
            $this->error('Web app source not found. This CLI version may not include the web app.');

            return self::FAILURE;
        }

        // Check if already installed
        if (File::isDirectory($destPath) && ! $this->option('force')) {
            $this->info('Web app already installed. Use --force to reinstall.');

            return self::SUCCESS;
        }

        $this->info('Installing companion web app...');

        // Copy web app files
        $this->task('Copying web app files', function () use ($sourcePath, $destPath) {
            $this->copyWebAppDirectory($sourcePath, $destPath);

            return true;
        });

        // Generate .env file
        $this->task('Generating environment file', function () use ($configManager) {
            $this->generateWebAppEnv($configManager);

            return true;
        });

        // Run composer install
        $this->task('Installing dependencies', function () use ($destPath) {
            $result = Process::timeout(300)
                ->path($destPath)
                ->run('composer install --no-dev --no-interaction --optimize-autoloader');

            return $result->successful();
        });

        // Regenerate Caddyfile to include launchpad.{tld}
        $this->task('Updating Caddy configuration', function () {
            $result = Process::run('launchpad caddy:generate 2>/dev/null');

            return $result->successful();
        });

        $this->newLine();
        $this->info('Web app installed successfully!');
        $this->info('');
        $this->info('To complete setup:');
        $this->info('  1. Restart launchpad: launchpad restart');
        $this->info('  2. Horizon will start automatically via cron');
        $tld = $configManager->getTld();
        $this->info("  3. Access at: https://launchpad.{$tld}");

        return self::SUCCESS;
    }

    protected function copyWebAppDirectory(string $source, string $destination): void
    {
        $excludeDirs = ['vendor', 'node_modules', '.git', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'];
        $excludeFiles = ['.env'];

        File::ensureDirectoryExists($destination);

        $this->recursiveCopy($source, $destination, $excludeDirs, $excludeFiles);

        // Ensure storage directories exist with proper permissions
        $storageDirs = [
            "{$destination}/storage/app",
            "{$destination}/storage/framework/cache",
            "{$destination}/storage/framework/sessions",
            "{$destination}/storage/framework/views",
            "{$destination}/storage/logs",
            "{$destination}/bootstrap/cache",
        ];

        foreach ($storageDirs as $dir) {
            File::ensureDirectoryExists($dir);
            chmod($dir, 0775);
        }
    }

    protected function recursiveCopy(string $source, string $destination, array $excludeDirs, array $excludeFiles, string $relativePath = ''): void
    {
        $items = File::files($source);
        $directories = File::directories($source);

        // Copy files
        foreach ($items as $file) {
            $filename = $file->getFilename();
            if (in_array($filename, $excludeFiles)) {
                continue;
            }
            File::copy($file->getPathname(), "{$destination}/{$filename}");
        }

        // Copy directories recursively
        foreach ($directories as $dir) {
            $dirname = basename($dir);
            $newRelativePath = $relativePath ? "{$relativePath}/{$dirname}" : $dirname;

            // Skip excluded directories
            $skip = false;
            foreach ($excludeDirs as $excludeDir) {
                if ($dirname === $excludeDir || str_starts_with($newRelativePath, $excludeDir)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $newDest = "{$destination}/{$dirname}";
            File::ensureDirectoryExists($newDest);
            $this->recursiveCopy($dir, $newDest, $excludeDirs, $excludeFiles, $newRelativePath);
        }
    }

    protected function generateWebAppEnv(ConfigManager $configManager): void
    {
        $webAppPath = $configManager->getWebAppPath();
        $tld = $configManager->getTld();
        $reverbConfig = $configManager->getReverbConfig();

        // Keep existing APP_KEY if present
        $existingEnv = File::exists("{$webAppPath}/.env")
            ? parse_ini_file("{$webAppPath}/.env")
            : [];
        $appKey = $existingEnv['APP_KEY'] ?? 'base64:'.base64_encode(random_bytes(32));

        $env = <<<ENV
APP_NAME=Launchpad
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL=https://launchpad.{$tld}

LOG_CHANNEL=single
LOG_LEVEL=error

# Stateless - no database needed
DB_CONNECTION=null

# Redis for everything
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue via Redis
QUEUE_CONNECTION=redis

# Let Horizon track failed jobs in Redis
QUEUE_FAILED_DRIVER=null

# Cache and sessions via Redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

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
