<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Process;

class MacAdapter implements PlatformAdapter
{
    /**
     * Install a PHP version with FPM.
     */
    public function installPhp(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);

        // Ensure Homebrew is installed
        if (! $this->isHomebrewInstalled()) {
            throw new \RuntimeException('Homebrew is not installed. Install from https://brew.sh');
        }

        // Add shivammathur/php tap if not already added
        $this->ensurePhpTapAdded();

        // Install PHP version
        $result = Process::run("brew install shivammathur/php/php@{$normalizedVersion}");

        return $result->successful();
    }

    /**
     * Check if a PHP version is installed.
     */
    public function isPhpInstalled(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run('brew list '.$this->getBrewPhpFormula($normalizedVersion));

        return $result->successful();
    }

    /**
     * Get all installed PHP versions.
     */
    public function getInstalledPhpVersions(): array
    {
        $result = Process::run("brew list 2>/dev/null | grep -E '^php(@[0-9.]+)?$' | sed 's/php@//; s/^php$/8.5/'");

        if (! $result->successful()) {
            return [];
        }

        return array_filter(explode("\n", trim($result->output())));
    }

    /**
     * Start PHP-FPM service for a version.
     */
    public function startPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run('brew services start '.$this->getBrewPhpFormula($normalizedVersion));

        return $result->successful();
    }

    /**
     * Stop PHP-FPM service for a version.
     */
    public function stopPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run('brew services stop '.$this->getBrewPhpFormula($normalizedVersion));

        return $result->successful();
    }

    /**
     * Restart PHP-FPM service for a version.
     */
    public function restartPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run('brew services restart '.$this->getBrewPhpFormula($normalizedVersion));

        return $result->successful();
    }

    /**
     * Gracefully reload PHP-FPM service for a version.
     * Sends SIGUSR2 to master process for graceful reload.
     */
    public function reloadPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $normalized = str_replace('.', '', $normalizedVersion);

        // Find PHP-FPM master process and send SIGUSR2 for graceful reload
        $result = Process::run("pkill -USR2 -f 'php-fpm.*{$normalized}.*master'");

        // If no master process found, try a regular restart
        if (! $result->successful()) {
            return $this->restartPhpFpm($version);
        }

        return true;
    }

    /**
     * Check if PHP-FPM service is running for a version.
     */
    public function isPhpFpmRunning(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        // Process names contain version with dot (e.g. "php-fpm: pool orbit-8.4")
        $result = Process::run("pgrep -f 'php-fpm.*{$normalizedVersion}' > /dev/null");

        return $result->successful();
    }

    /**
     * Get the socket path for a PHP version.
     */
    public function getSocketPath(string $version): string
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $normalized = str_replace('.', '', $normalizedVersion); // Remove dot: 8.4 -> 84

        // Use custom orbit socket path for consistency
        return $this->getHomePath()."/.config/orbit/php/php{$normalized}.sock";
    }

    /**
     * Check if Homebrew is installed.
     */
    protected function isHomebrewInstalled(): bool
    {
        $result = Process::run('which brew');

        return $result->successful();
    }

    /**
     * Ensure shivammathur/php tap is added.
     */
    protected function ensurePhpTapAdded(): void
    {
        // Check if tap is already added
        $result = Process::run('brew tap | grep -q shivammathur/php');

        if (! $result->successful()) {
            Process::run('brew tap shivammathur/php');
        }
    }

    /**
     * Get the Caddyfile path.
     */
    protected function getCaddyfilePath(): string
    {
        return $this->getHomePath().'/.config/orbit/caddy/Caddyfile';
    }

    public function getPhpBinaryPath(string $version): string
    {
        $normalizedVersion = $this->normalizePhpVersion($version);

        return '/opt/homebrew/opt/'.$this->getBrewPhpFormula($normalizedVersion).'/bin/php';
    }

    public function getPoolConfigDir(string $version): string
    {
        $normalizedVersion = $this->normalizePhpVersion($version);

        return '/opt/homebrew/etc/php/'.$normalizedVersion.'/php-fpm.d';
    }

    public function installCaddy(): bool
    {
        $result = Process::run('brew install caddy');

        return $result->successful();
    }

    public function isCaddyInstalled(): bool
    {
        $result = Process::run('which caddy');

        return $result->successful();
    }

    public function startCaddy(): bool
    {
        $result = Process::run('brew services start caddy');

        return $result->successful();
    }

    public function stopCaddy(): bool
    {
        $result = Process::run('brew services stop caddy');

        return $result->successful();
    }

    public function restartCaddy(): bool
    {
        $result = Process::run('brew services restart caddy');

        return $result->successful();
    }

    public function reloadCaddy(): bool
    {
        $result = Process::run('caddy reload --config '.$this->getCaddyfilePath());

        return $result->successful();
    }

    public function isCaddyRunning(): bool
    {
        $result = Process::run('pgrep -x caddy > /dev/null');

        return $result->successful();
    }

    public function getUser(): string
    {
        return trim(Process::run('whoami')->output());
    }

    public function getGroup(): string
    {
        return trim(Process::run('id -gn')->output());
    }

    public function getHomePath(): string
    {
        return trim(Process::run('echo $HOME')->output());
    }

    /**
     * Set the default PHP CLI version using brew link.
     */
    public function setDefaultPhpCli(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $formula = $this->getBrewPhpFormula($normalizedVersion);

        // First unlink all PHP versions, then link the requested one
        Process::run('brew unlink php 2>/dev/null');
        foreach (['8.3', '8.4', '8.5'] as $v) {
            if ($v !== $normalizedVersion) {
                Process::run("brew unlink php@{$v} 2>/dev/null");
            }
        }

        $result = Process::run("brew link --force --overwrite {$formula}");

        return $result->successful();
    }

    /**
     * Get the current default PHP CLI version.
     */
    public function getDefaultPhpCli(): ?string
    {
        $result = Process::run('php --version 2>/dev/null | head -1');

        if (! $result->successful()) {
            return null;
        }

        // Parse "PHP 8.4.3 (cli) ..." to extract "8.4"
        if (preg_match('/PHP (\d+\.\d+)/', $result->output(), $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Normalize PHP version string.
     * Converts "8.4", "php8.4", "84" → "8.4"
     */
    protected function normalizePhpVersion(string $version): string
    {
        // Remove 'php@' and 'php' prefixes
        $version = str_replace(['php@', 'php'], '', $version);

        // If no dot, add it (84 -> 8.4)
        if (! str_contains($version, '.')) {
            $version = substr($version, 0, 1).'.'.substr($version, 1);
        }

        return $version;
    }

    /**
     * Get the Homebrew formula name for a PHP version.
     * PHP 8.5 is the current default and uses just 'php', older versions use 'php@X.Y'.
     */
    protected function getBrewPhpFormula(string $version): string
    {
        $normalized = $this->normalizePhpVersion($version);

        // PHP 8.5 is the current Homebrew default (no version suffix)
        if ($normalized === '8.5') {
            return 'php';
        }

        return 'php@'.$normalized;
    }
}
