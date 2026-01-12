<?php

namespace App\Commands;

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class InitCommand extends Command
{
    protected $signature = 'init';

    protected $description = 'First-time setup: creates config directory, pulls images, sets up DNS';

    public function handle(
        ConfigManager $configManager,
        DockerManager $dockerManager,
        CaddyfileGenerator $caddyfileGenerator
    ): int {
        $this->info('Creating Launchpad...');

        // Check if Docker is running
        $dockerCheck = Process::run('docker info');
        if (! $dockerCheck->successful()) {
            $this->error('Docker is not running. Please start Docker/OrbStack first.');

            return self::FAILURE;
        }

        $configPath = $configManager->getConfigPath();

        // 1. Create directory structure
        $this->task('Creating directories', function () use ($configPath) {
            $directories = [
                $configPath,
                "{$configPath}/php",
                "{$configPath}/caddy",
                "{$configPath}/dns",
                "{$configPath}/postgres",
                "{$configPath}/postgres/data",
                "{$configPath}/redis",
                "{$configPath}/redis/data",
                "{$configPath}/mailpit",
                "{$configPath}/logs",
            ];

            foreach ($directories as $dir) {
                File::ensureDirectoryExists($dir);
            }

            return true;
        });

        // 2. Copy stubs
        $this->task('Copying configuration files', function () use ($configPath) {
            $stubsPath = base_path('stubs');

            // Copy all stub files
            $this->copyStubDirectory("{$stubsPath}/php", "{$configPath}/php");
            $this->copyStubDirectory("{$stubsPath}/caddy", "{$configPath}/caddy");
            $this->copyStubDirectory("{$stubsPath}/dns", "{$configPath}/dns");
            $this->copyStubDirectory("{$stubsPath}/postgres", "{$configPath}/postgres");
            $this->copyStubDirectory("{$stubsPath}/redis", "{$configPath}/redis");
            $this->copyStubDirectory("{$stubsPath}/mailpit", "{$configPath}/mailpit");

            // Copy config.json if it doesn't exist
            if (! File::exists("{$configPath}/config.json")) {
                File::copy("{$stubsPath}/config.json", "{$configPath}/config.json");
            }

            // Copy CLAUDE.md
            File::copy("{$stubsPath}/CLAUDE.md", "{$configPath}/CLAUDE.md");

            return true;
        });

        // 3. Install companion web app
        $this->task('Installing companion web app', function () use ($configManager) {
            return $this->installWebApp($configManager);
        });

        // 4. Generate initial Caddyfile
        $this->task('Generating Caddyfile', function () use ($caddyfileGenerator) {
            $caddyfileGenerator->generate();

            return true;
        });

        // 5. Create docker network
        $this->task('Creating Docker network', $dockerManager->createNetwork(...));

        // 6. Build DNS container
        $this->task('Building DNS container', function () use ($dockerManager) {
            $result = $dockerManager->build('dns');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        // 7. Build PHP images (with Redis and other extensions)
        $this->task('Building PHP images (this may take a while)', function () use ($dockerManager) {
            $result = $dockerManager->build('php');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        // 8. Pull service images
        $this->task('Pulling Caddy image', function () use ($dockerManager) {
            $result = $dockerManager->pull('caddy');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->task('Pulling Postgres image', function () use ($dockerManager) {
            $result = $dockerManager->pull('postgres');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->task('Pulling Redis image', function () use ($dockerManager) {
            $result = $dockerManager->pull('redis');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        $this->task('Pulling Mailpit image', function () use ($dockerManager) {
            $result = $dockerManager->pull('mailpit');
            if (! $result && $dockerManager->getLastError()) {
                $this->output->write(" <fg=red>{$dockerManager->getLastError()}</>");
            }

            return $result;
        });

        // 9. Install composer-link globally for package development
        $this->task('Installing composer-link plugin', function () {
            $result = Process::run('composer global config --no-plugins allow-plugins.sandersander/composer-link true && composer global require sandersander/composer-link --quiet');

            return $result->successful();
        });

        // 10. Install cron job for ensure command
        $this->task('Installing cron job', function () {
            return $this->installCronJob();
        });

        $this->newLine();
        $this->warn('Point your DNS to 127.0.0.1:');
        $this->line('  System Settings → Network → Wi-Fi → Details → DNS');
        $this->line('  Or: sudo networksetup -setdnsservers Wi-Fi 127.0.0.1');

        $this->newLine();
        $this->info('Done! Run: launchpad start');

        return self::SUCCESS;
    }

    protected function copyStubDirectory(string $source, string $destination): void
    {
        if (! File::isDirectory($source)) {
            return;
        }

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

    protected function installWebApp(ConfigManager $configManager): bool
    {
        $sourcePath = base_path('web');
        $destPath = $configManager->getWebAppPath();

        // Check if source exists (in development or phar)
        if (! File::isDirectory($sourcePath)) {
            // Source not found - this might be a minimal CLI installation
            return true;
        }

        // Copy web app files (excluding vendor, node_modules, .env)
        $this->copyWebAppDirectory($sourcePath, $destPath);

        // Generate .env file
        $this->generateWebAppEnv($configManager);

        // Run composer install
        $result = Process::timeout(300)
            ->path($destPath)
            ->run('composer install --no-dev --no-interaction --optimize-autoloader');

        return $result->successful();
    }

    protected function copyWebAppDirectory(string $source, string $destination): void
    {
        $excludeDirs = ['vendor', 'node_modules', '.git', 'storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'];
        $excludeFiles = ['.env'];

        File::ensureDirectoryExists($destination);

        // Copy files recursively, excluding specified paths
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

        // Generate a random app key
        $appKey = 'base64:'.base64_encode(random_bytes(32));

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

    protected function installCronJob(): bool
    {
        $launchpadBin = (getenv('HOME') ?: '/home/launchpad').'/.local/bin/launchpad';
        $logPath = (getenv('HOME') ?: '/home/launchpad').'/.config/launchpad/logs/ensure.log';
        $cronEntry = "* * * * * {$launchpadBin} ensure >> {$logPath} 2>&1";

        // Get current crontab
        $result = Process::run('crontab -l 2>/dev/null');
        $currentCrontab = $result->successful() ? trim($result->output()) : '';

        // Check if entry already exists
        if (str_contains($currentCrontab, 'launchpad ensure')) {
            return true;
        }

        // Add new entry
        $newCrontab = $currentCrontab ? "{$currentCrontab}\n{$cronEntry}" : $cronEntry;

        // Install new crontab
        $result = Process::run("echo \"{$newCrontab}\" | crontab -");

        return $result->successful();
    }
}
