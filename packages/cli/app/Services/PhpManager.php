<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Platform\LinuxAdapter;
use App\Services\Platform\MacAdapter;
use App\Services\Platform\PlatformAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PhpManager
{
    protected PlatformAdapter $adapter;

    public function __construct(protected ConfigManager $configManager)
    {
        $this->adapter = $this->detectPlatform();
    }

    /**
     * Install a PHP version with FPM.
     */
    public function install(string $version): bool
    {
        return $this->adapter->installPhp($version);
    }

    /**
     * Check if a PHP version is installed.
     */
    public function isInstalled(string $version): bool
    {
        return $this->adapter->isPhpInstalled($version);
    }

    /**
     * Get all installed PHP versions.
     */
    public function getInstalledVersions(): array
    {
        return $this->adapter->getInstalledPhpVersions();
    }

    /**
     * Start PHP-FPM service for a version.
     */
    public function start(string $version): bool
    {
        return $this->adapter->startPhpFpm($version);
    }

    /**
     * Stop PHP-FPM service for a version.
     */
    public function stop(string $version): bool
    {
        return $this->adapter->stopPhpFpm($version);
    }

    /**
     * Restart PHP-FPM service for a version.
     */
    public function restart(string $version): bool
    {
        return $this->adapter->restartPhpFpm($version);
    }

    /**
     * Gracefully reload PHP-FPM service for a version.
     */
    public function reload(string $version): bool
    {
        return $this->adapter->reloadPhpFpm($version);
    }

    /**
     * Check if PHP-FPM service is running for a version.
     */
    public function isRunning(string $version): bool
    {
        return $this->adapter->isPhpFpmRunning($version);
    }

    /**
     * Get the socket path for a PHP version.
     */
    public function getSocketPath(string $version): string
    {
        return $this->adapter->getSocketPath($version);
    }

    /**
     * Get the path to the PHP binary for a version.
     */
    public function getPhpBinaryPath(string $version): string
    {
        return $this->adapter->getPhpBinaryPath($version);
    }

    /**
     * Configure FPM pool for a PHP version.
     * Creates custom pool config using stub template.
     */
    public function configurePool(string $version): void
    {
        $normalizedVersion = $this->normalizeVersion($version);

        // Create socket directory if it doesn't exist
        $socketDir = dirname($this->getSocketPath($version));
        if (! File::isDirectory($socketDir)) {
            File::makeDirectory($socketDir, 0755, true);
        }

        // Load stub template
        $stub = File::get($this->stubPath('php-fpm-pool.conf.stub'));

        // Replace placeholders
        $config = str_replace([
            'ORBIT_PHP_VERSION',
            'ORBIT_USER',
            'ORBIT_GROUP',
            'ORBIT_SOCKET_PATH',
            'ORBIT_LOG_PATH',
            'ORBIT_ENV_PATH',
            'ORBIT_HOME',
        ], [
            $normalizedVersion,
            $this->adapter->getUser(),
            $this->adapter->getGroup(),
            $this->getSocketPath($version),
            $this->getLogPath($version),
            $this->getEnvPath(),
            $this->adapter->getHomePath(),
        ], $stub);

        // Write pool configuration
        $poolConfigPath = $this->getPoolConfigPath($version);
        File::put($poolConfigPath, $config);
    }

    /**
     * Create FPM pool configuration for a PHP version.
     * This is an alias for configurePool() for consistency with migration plan.
     */
    public function createPoolConfig(string $version): void
    {
        $this->configurePool($version);
    }

    /**
     * Install a custom pool configuration for a PHP version.
     * Creates the pool config and symlinks it to the system FPM directory.
     */
    public function installPool(string $version): bool
    {
        // Ensure socket directory exists with correct permissions
        $socketDir = $this->adapter->getHomePath().'/.config/orbit/php';
        if (! File::isDirectory($socketDir)) {
            File::makeDirectory($socketDir, 0755, true);
        }

        // Create the pool configuration
        $this->configurePool($version);

        $poolConfigPath = $this->getPoolConfigPath($version);
        $systemPoolDir = $this->adapter->getPoolConfigDir($version);
        $normalized = $this->normalizeVersion($version);
        $symlinkPath = "{$systemPoolDir}/orbit-{$normalized}.conf";

        // Create symlink to system pool.d directory
        $result = Process::run("sudo ln -sf $poolConfigPath $symlinkPath");

        if (! $result->successful()) {
            return false;
        }

        // Restart PHP-FPM to pick up the new pool
        return $this->restart($version);
    }

    /**
     * Remove a custom pool configuration for a PHP version.
     */
    public function removePool(string $version): bool
    {
        $systemPoolDir = $this->adapter->getPoolConfigDir($version);
        $normalized = $this->normalizeVersion($version);
        $symlinkPath = "{$systemPoolDir}/orbit-{$normalized}.conf";

        // Remove symlink from system pool.d directory
        $result = Process::run("sudo rm -f $symlinkPath");

        if (! $result->successful()) {
            return false;
        }

        // Remove the pool config file
        $poolConfigPath = $this->getPoolConfigPath($version);
        if (File::exists($poolConfigPath)) {
            File::delete($poolConfigPath);
        }

        // Restart PHP-FPM to remove the pool
        return $this->restart($version);
    }

    /**
     * Restart the PHP-FPM pool for a version.
     */
    public function restartPool(string $version): bool
    {
        return $this->restart($version);
    }

    /**
     * Get the pool configuration path for a PHP version.
     */
    public function getPoolConfigPath(string $version): string
    {
        $normalizedVersion = $this->normalizeVersion($version);
        $poolDir = $this->adapter->getPoolConfigDir($version);

        return "{$poolDir}/orbit-{$normalizedVersion}.conf";
    }

    /**
     * Get the log path for a PHP version.
     */
    public function getLogPath(string $version): string
    {
        $normalizedVersion = $this->normalizeVersion($version);
        $logDir = $this->configManager->getConfigPath().'/logs';

        if (! File::isDirectory($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        return "$logDir/php$normalizedVersion-fpm.log";
    }

    /**
     * Get the environment PATH for FPM processes.
     */
    public function getEnvPath(): string
    {
        $home = $this->adapter->getHomePath();

        return implode(':', [
            "$home/.local/bin",
            "$home/.bun/bin",
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ]);
    }

    /**
     * Normalize PHP version string.
     * Converts "8.4", "php8.4", "84" → "84"
     * Inspired by Valet's normalizePhpVersion().
     */
    public function normalizeVersion(string $version): string
    {
        // Remove 'php@' and 'php' prefixes first, then dots
        $version = str_replace(['php@', 'php'], '', $version);

        // Remove dots to get format "84" from "8.4"
        return str_replace('.', '', $version);
    }

    /**
     * Get the stub file path.
     */
    protected function stubPath(string $stub): string
    {
        return __DIR__.'/../../stubs/'.$stub;
    }

    /**
     * Detect the current platform and return appropriate adapter.
     */
    protected function detectPlatform(): PlatformAdapter
    {
        $os = php_uname('s');

        if (stripos($os, 'Darwin') !== false) {
            return new MacAdapter;
        }

        if (stripos($os, 'Linux') !== false) {
            return new LinuxAdapter;
        }

        throw new \RuntimeException("Unsupported platform: {$os}");
    }

    /**
     * Get the platform adapter instance.
     */
    public function getAdapter(): PlatformAdapter
    {
        return $this->adapter;
    }
}
