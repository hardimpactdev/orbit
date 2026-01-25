<?php

declare(strict_types=1);

namespace App\Actions\Upgrade;

use App\Services\ConfigManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final readonly class UpdateWebApp
{
    /**
     * Required .env keys that must be present for the web app to function.
     * These will be added/updated during upgrade if missing or outdated.
     */
    private const REQUIRED_ENV_KEYS = [
        'APP_URL',
        'APP_ENV',
        'ORBIT_MODE',
        'ORBIT_CLI_PATH',
        'DB_DATABASE',
        'BROADCAST_CONNECTION',
        'REVERB_APP_ID',
        'REVERB_APP_KEY',
        'REVERB_APP_SECRET',
        'REVERB_HOST',
        'REVERB_PORT',
        'REVERB_SCHEME',
        'VITE_REVERB_APP_KEY',
        'VITE_REVERB_HOST',
        'VITE_REVERB_PORT',
        'VITE_REVERB_SCHEME',
    ];

    public function __construct(
        private ConfigManager $configManager,
    ) {}

    public function handle(): bool
    {
        $sourcePath = base_path('web');
        $destPath = $this->configManager->getWebAppPath();

        // Check if source exists (in development or phar)
        if (! File::isDirectory($sourcePath)) {
            // Source not found - nothing to update
            return true;
        }

        // Check if web app is installed
        if (! File::exists("{$destPath}/artisan")) {
            // Web app not installed - skip update
            return true;
        }

        // Backup database
        $dbPath = "{$destPath}/database.sqlite";
        if (File::exists($dbPath)) {
            File::copy($dbPath, "{$dbPath}.backup");
        }

        // Save existing .env
        $envPath = "{$destPath}/.env";
        $envContent = '';
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
        }

        // Copy new files (preserving certain directories and files)
        $this->updateWebAppFiles($sourcePath, $destPath);

        // Restore .env and ensure required keys are present
        if ($envContent) {
            $envContent = $this->migrateEnv($envContent);
            File::put($envPath, $envContent);
        } else {
            // No .env exists, generate a fresh one
            $this->generateFreshEnv($envPath);
        }

        // Clear caches
        Process::timeout(60)
            ->path($destPath)
            ->run('php artisan cache:clear');

        Process::timeout(60)
            ->path($destPath)
            ->run('php artisan config:clear');

        Process::timeout(60)
            ->path($destPath)
            ->run('php artisan view:clear');

        // Remove path repositories and lock file to ensure fresh dependency resolution
        Process::timeout(60)
            ->path($destPath)
            ->run('composer config --unset repositories');

        if (File::exists("{$destPath}/composer.lock")) {
            File::delete("{$destPath}/composer.lock");
        }

        // Run composer install (will resolve from packagist)
        $result = Process::timeout(300)
            ->path($destPath)
            ->run('composer install --no-dev --no-interaction --optimize-autoloader');

        if (! $result->successful()) {
            // Restore database backup on failure
            if (File::exists("{$dbPath}.backup")) {
                File::move("{$dbPath}.backup", $dbPath);
            }

            return false;
        }

        // Run migrations
        $migrateResult = Process::timeout(60)
            ->path($destPath)
            ->run('php artisan migrate --force');

        if (! $migrateResult->successful()) {
            // Restore database backup on failure
            if (File::exists("{$dbPath}.backup")) {
                File::move("{$dbPath}.backup", $dbPath);
            }

            return false;
        }

        // Clean up backup on success
        if (File::exists("{$dbPath}.backup")) {
            File::delete("{$dbPath}.backup");
        }

        return true;
    }

    private function updateWebAppFiles(string $source, string $destination): void
    {
        // Preserve these directories/files during update
        $preserveDirs = ['storage', '.env', 'database.sqlite'];
        $excludeDirs = ['vendor', 'node_modules', '.git'];

        // First, remove old files (except preserved ones)
        $this->cleanOldFiles($destination, $preserveDirs);

        // Then copy new files
        $this->recursiveCopy($source, $destination, $excludeDirs, ['.env']);

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
     * @param  array<int, string>  $preserve
     */
    private function cleanOldFiles(string $path, array $preserve): void
    {
        $items = File::files($path);
        $directories = File::directories($path);

        // Remove files
        foreach ($items as $file) {
            $filename = $file->getFilename();
            if (! in_array($filename, $preserve)) {
                File::delete($file->getPathname());
            }
        }

        // Remove directories
        foreach ($directories as $dir) {
            $dirname = basename((string) $dir);
            if (! in_array($dirname, $preserve)) {
                File::deleteDirectory($dir);
            }
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

    /**
     * Migrate existing .env content to ensure all required keys are present.
     */
    private function migrateEnv(string $envContent): string
    {
        $lines = explode("\n", $envContent);
        $existingKeys = [];

        // Parse existing keys
        foreach ($lines as $line) {
            if (preg_match('/^([A-Z_]+)=/', $line, $matches)) {
                $existingKeys[$matches[1]] = true;
            }
        }

        $requiredValues = $this->getRequiredEnvValues();
        $additions = [];

        // Check for missing required keys
        foreach (self::REQUIRED_ENV_KEYS as $key) {
            if (! isset($existingKeys[$key]) && isset($requiredValues[$key])) {
                $additions[] = "{$key}={$requiredValues[$key]}";
            }
        }

        // Fix specific values that may be outdated
        $envContent = $this->fixOutdatedEnvValues($envContent, $requiredValues);

        // Append missing keys
        if (! empty($additions)) {
            $envContent = rtrim($envContent)."\n\n# Added during upgrade\n".implode("\n", $additions)."\n";
        }

        return $envContent;
    }

    /**
     * Fix specific .env values that may be outdated.
     */
    private function fixOutdatedEnvValues(string $envContent, array $requiredValues): string
    {
        $tld = $this->configManager->getTld();

        // Ensure APP_URL points to orbit.{tld}
        $envContent = (string) preg_replace(
            '/^APP_URL=.*$/m',
            "APP_URL=https://orbit.{$tld}",
            $envContent
        );

        // Ensure APP_ENV is production for bundled web app
        $envContent = (string) preg_replace(
            '/^APP_ENV=.*$/m',
            'APP_ENV=production',
            $envContent
        );

        // Ensure DB_DATABASE points to the shared database
        $sharedDbPath = $this->getSharedDatabasePath();
        $envContent = (string) preg_replace(
            '/^DB_DATABASE=.*$/m',
            "DB_DATABASE={$sharedDbPath}",
            $envContent
        );

        // Ensure BROADCAST_CONNECTION is reverb
        $envContent = (string) preg_replace(
            '/^BROADCAST_CONNECTION=.*$/m',
            'BROADCAST_CONNECTION=reverb',
            $envContent
        );

        // Update ORBIT_CLI_PATH to current CLI path
        $cliPath = $this->getInstalledCliPath();
        if (preg_match('/^ORBIT_CLI_PATH=.*$/m', $envContent)) {
            $envContent = (string) preg_replace(
                '/^ORBIT_CLI_PATH=.*$/m',
                "ORBIT_CLI_PATH={$cliPath}",
                $envContent
            );
        }

        return $envContent;
    }

    /**
     * Get required .env values based on current configuration.
     *
     * @return array<string, string>
     */
    private function getRequiredEnvValues(): array
    {
        $tld = $this->configManager->getTld();
        $reverbConfig = $this->configManager->getReverbConfig();
        $cliPath = $this->getInstalledCliPath();
        $dbPath = $this->getSharedDatabasePath();

        return [
            'APP_URL' => "https://orbit.{$tld}",
            'APP_ENV' => 'production',
            'ORBIT_MODE' => 'web',
            'ORBIT_CLI_PATH' => $cliPath,
            'DB_DATABASE' => $dbPath,
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => $reverbConfig['app_id'],
            'REVERB_APP_KEY' => $reverbConfig['app_key'],
            'REVERB_APP_SECRET' => $reverbConfig['app_secret'],
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => (string) $reverbConfig['internal_port'],
            'REVERB_SCHEME' => 'http',
            'VITE_REVERB_APP_KEY' => $reverbConfig['app_key'],
            'VITE_REVERB_HOST' => "reverb.{$tld}",
            'VITE_REVERB_PORT' => '443',
            'VITE_REVERB_SCHEME' => 'https',
        ];
    }

    /**
     * Get the shared database path (used by both CLI and web).
     */
    private function getSharedDatabasePath(): string
    {
        return $this->configManager->getConfigPath().'/database.sqlite';
    }

    /**
     * Get the installed CLI path.
     */
    private function getInstalledCliPath(): string
    {
        return (getenv('HOME') ?: '/home/orbit').'/.local/bin/orbit';
    }

    /**
     * Generate a fresh .env file when none exists.
     */
    private function generateFreshEnv(string $envPath): void
    {
        $tld = $this->configManager->getTld();
        $reverbConfig = $this->configManager->getReverbConfig();
        $cliPath = $this->getInstalledCliPath();
        $dbPath = $this->getSharedDatabasePath();

        $appKey = 'base64:'.base64_encode(random_bytes(32));

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

        File::put($envPath, $env);
    }
}
