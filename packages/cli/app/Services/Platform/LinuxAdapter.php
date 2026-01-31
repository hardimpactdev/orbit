<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Facades\Process;

final class LinuxAdapter implements PlatformAdapter
{
    /**
     * Install a PHP version with FPM.
     */
    public function installPhp(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);

        // Add Ondřej PPA if not already added
        $this->ensureOndrejPpaAdded();

        // Install PHP-FPM and common extensions
        $extensions = [
            "php{$normalizedVersion}-fpm",
            "php{$normalizedVersion}-cli",
            "php{$normalizedVersion}-mbstring",
            "php{$normalizedVersion}-xml",
            "php{$normalizedVersion}-curl",
            "php{$normalizedVersion}-zip",
            "php{$normalizedVersion}-gd",
            "php{$normalizedVersion}-pgsql",
            "php{$normalizedVersion}-mysql",
            "php{$normalizedVersion}-redis",
            "php{$normalizedVersion}-sqlite3",
            "php{$normalizedVersion}-bcmath",
            "php{$normalizedVersion}-intl",
            "php{$normalizedVersion}-pcntl",
        ];

        $packages = implode(' ', $extensions);
        $result = Process::run("sudo apt-get install -y {$packages}");

        return $result->successful();
    }

    /**
     * Check if a PHP version is installed.
     */
    public function isPhpInstalled(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run("dpkg -l | grep -q php{$normalizedVersion}-fpm");

        return $result->successful();
    }

    /**
     * Get all installed PHP versions.
     */
    public function getInstalledPhpVersions(): array
    {
        $result = Process::run("dpkg -l | grep 'php[0-9]\\.[0-9]-fpm' | awk '{print \$2}' | sed 's/php\\([0-9]\\.[0-9]\\)-fpm/\\1/'");

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
        $result = Process::run("sudo systemctl start php{$normalizedVersion}-fpm");

        return $result->successful();
    }

    /**
     * Stop PHP-FPM service for a version.
     */
    public function stopPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run("sudo systemctl stop php{$normalizedVersion}-fpm");

        return $result->successful();
    }

    /**
     * Restart PHP-FPM service for a version.
     */
    public function restartPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run("sudo systemctl restart php{$normalizedVersion}-fpm");

        return $result->successful();
    }

    /**
     * Gracefully reload PHP-FPM service for a version.
     * Uses SIGUSR2 to reload workers without killing active connections.
     */
    public function reloadPhpFpm(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run("sudo systemctl reload php{$normalizedVersion}-fpm");

        return $result->successful();
    }

    /**
     * Check if PHP-FPM service is running for a version.
     */
    public function isPhpFpmRunning(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $result = Process::run("systemctl is-active --quiet php{$normalizedVersion}-fpm");

        return $result->successful();
    }

    /**
     * Get the socket path for a PHP version.
     * Returns custom orbit socket path using format without dots (php84.sock not php8.4.sock).
     */
    public function getSocketPath(string $version): string
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $normalized = str_replace('.', '', $normalizedVersion); // Remove dot: 8.4 -> 84

        // Use custom orbit socket path for consistency
        return $this->getHomePath()."/.config/orbit/php/php{$normalized}.sock";
    }

    /**
     * Get the path to the PHP binary for a version.
     */
    public function getPhpBinaryPath(string $version): string
    {
        $normalizedVersion = $this->normalizePhpVersion($version);

        return "/usr/bin/php{$normalizedVersion}";
    }

    /**
     * Get the FPM pool configuration directory.
     */
    public function getPoolConfigDir(string $version): string
    {
        $normalizedVersion = $this->normalizePhpVersion($version);

        return "/etc/php/{$normalizedVersion}/fpm/pool.d";
    }

    /**
     * Install Caddy web server.
     */
    public function installCaddy(): bool
    {
        // Install prerequisites
        Process::run('sudo apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl');

        // Add Caddy repository
        Process::run("curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg");
        Process::run("curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list");

        // Update and install
        Process::run('sudo apt-get update');
        $result = Process::run('sudo apt-get install -y caddy');

        return $result->successful();
    }

    /**
     * Check if Caddy is installed.
     */
    public function isCaddyInstalled(): bool
    {
        $result = Process::run('which caddy');

        return $result->successful();
    }

    /**
     * Start Caddy service.
     */
    public function startCaddy(): bool
    {
        $result = Process::run('sudo systemctl start caddy');

        return $result->successful();
    }

    /**
     * Stop Caddy service.
     */
    public function stopCaddy(): bool
    {
        $result = Process::run('sudo systemctl stop caddy');

        return $result->successful();
    }

    /**
     * Restart Caddy service.
     */
    public function restartCaddy(): bool
    {
        $result = Process::run('sudo systemctl restart caddy');

        return $result->successful();
    }

    /**
     * Reload Caddy configuration without restarting.
     */
    public function reloadCaddy(): bool
    {
        $result = Process::run('sudo systemctl reload caddy');

        return $result->successful();
    }

    /**
     * Check if Caddy service is running.
     */
    public function isCaddyRunning(): bool
    {
        $result = Process::run('systemctl is-active --quiet caddy');

        return $result->successful();
    }

    /**
     * Get current user name.
     */
    public function getUser(): string
    {
        return trim(Process::run('whoami')->output());
    }

    /**
     * Get current group name.
     */
    public function getGroup(): string
    {
        return trim(Process::run('id -gn')->output());
    }

    /**
     * Get home directory path.
     */
    public function getHomePath(): string
    {
        return getenv('HOME') ?: '/home/'.posix_getpwuid(posix_getuid())['name'];
    }

    /**
     * Set the default PHP CLI version using update-alternatives.
     */
    public function setDefaultPhpCli(string $version): bool
    {
        $normalizedVersion = $this->normalizePhpVersion($version);
        $binaryPath = $this->getPhpBinaryPath($normalizedVersion);

        $result = Process::run("sudo update-alternatives --set php {$binaryPath}");

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
        // Remove 'php' and 'php@' prefixes
        $version = str_replace(['php@', 'php'], '', $version);

        // If it's already in format "8.4", return it
        if (preg_match('/^\d+\.\d+$/', $version)) {
            return $version;
        }

        // If it's in format "84", convert to "8.4"
        if (preg_match('/^\d{2}$/', $version)) {
            return substr($version, 0, 1).'.'.substr($version, 1);
        }

        return $version;
    }

    /**
     * Ensure Ondřej PPA is added for PHP packages.
     */
    protected function ensureOndrejPpaAdded(): void
    {
        // Check if PPA is already added
        $result = Process::run('grep -q "ondrej/php" /etc/apt/sources.list.d/*.list 2>/dev/null');

        if (! $result->successful()) {
            // Add the PPA
            Process::run('sudo add-apt-repository ppa:ondrej/php -y');
            Process::run('sudo apt-get update');
        }
    }
}
