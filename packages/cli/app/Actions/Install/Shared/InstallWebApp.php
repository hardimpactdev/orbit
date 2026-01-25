<?php

declare(strict_types=1);

namespace App\Actions\Install\Shared;

use App\Data\Install\InstallContext;
use App\Data\Provision\StepResult;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final readonly class InstallWebApp
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        $sourcePath = base_path('web');
        $destPath = $this->configManager->getWebAppPath();

        // Check if source exists (in development or phar)
        if (! File::isDirectory($sourcePath)) {
            $logger->skip('Web app source not found - skipping');

            return StepResult::success();
        }

        // Check if already installed
        if (File::exists("{$destPath}/artisan")) {
            $logger->skip('Web app already installed');

            return StepResult::success();
        }

        // Copy web app files
        $this->copyWebAppDirectory($sourcePath, $destPath);

        // Generate .env file
        $this->generateWebAppEnv($context);

        // Run composer install
        $result = Process::timeout(300)
            ->path($destPath)
            ->run('composer install --no-dev --no-interaction --optimize-autoloader');

        if (! $result->successful()) {
            return StepResult::failed('Failed to install web app dependencies: '.$result->errorOutput());
        }

        // Ensure SQLite database exists before migrations
        $dbPath = "{$destPath}/database.sqlite";
        if (! File::exists($dbPath)) {
            File::put($dbPath, '');
        }

        // Run migrations
        $migrateResult = Process::timeout(60)
            ->path($destPath)
            ->run('php artisan migrate --force');

        if (! $migrateResult->successful()) {
            return StepResult::failed('Failed to run web app migrations: '.$migrateResult->errorOutput());
        }

        // Seed local environment
        $hostname = gethostname() ?: 'Local';
        $seedResult = Process::timeout(60)
            ->path($destPath)
            ->run("php artisan orbit:init --name=\"{$hostname}\"");

        if (! $seedResult->successful()) {
            $logger->warn('Failed to seed web app - it may need manual setup: '.$seedResult->errorOutput());
        }

        $logger->success('Web app installed');

        return StepResult::success();
    }

    private function copyWebAppDirectory(string $source, string $destination): void
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

    /**
     * @param  array<int, string>  $excludeDirs
     * @param  array<int, string>  $excludeFiles
     */
    private function recursiveCopy(string $source, string $destination, array $excludeDirs, array $excludeFiles, string $relativePath = ''): void
    {
        $items = File::files($source);
        $directories = File::directories($source);

        foreach ($items as $file) {
            $filename = $file->getFilename();
            if (in_array($filename, $excludeFiles)) {
                continue;
            }
            File::copy($file->getPathname(), "{$destination}/{$filename}");
        }

        foreach ($directories as $dir) {
            $dirname = basename((string) $dir);
            $newRelativePath = $relativePath ? "{$relativePath}/{$dirname}" : $dirname;

            $skip = false;
            foreach ($excludeDirs as $excludeDir) {
                if ($dirname === $excludeDir || str_starts_with($newRelativePath, (string) $excludeDir)) {
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

    private function generateWebAppEnv(InstallContext $context): void
    {
        $configPath = $this->configManager->getConfigPath();
        $tld = $context->tld;
        $reverbConfig = $this->configManager->getReverbConfig();
        $cliPath = (getenv('HOME') ?: '/home/orbit').'/.local/bin/orbit';
        $dbPath = "{$configPath}/database.sqlite";

        $appKey = 'base64:'.base64_encode(random_bytes(32));

        // Bundled web app uses shared database and connects to Reverb
        $env = <<<ENV
APP_NAME=Orbit
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=true
APP_URL=https://orbit.{$tld}
ORBIT_MODE=web
ORBIT_CLI_PATH={$cliPath}

LOG_CHANNEL=single
LOG_LEVEL=error

DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}

REDIS_CLIENT=phpredis
REDIS_HOST=orbit-redis
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=null

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

BROADCAST_CONNECTION=reverb

REVERB_APP_ID={$reverbConfig['app_id']}
REVERB_APP_KEY={$reverbConfig['app_key']}
REVERB_APP_SECRET={$reverbConfig['app_secret']}
REVERB_HOST=127.0.0.1
REVERB_PORT={$reverbConfig['internal_port']}
REVERB_SCHEME=http

VITE_REVERB_APP_KEY={$reverbConfig['app_key']}
VITE_REVERB_HOST=reverb.{$tld}
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
ENV;

        File::put("{$configPath}/web/.env", $env);
    }
}
