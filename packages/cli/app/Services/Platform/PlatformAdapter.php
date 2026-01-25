<?php

namespace App\Services\Platform;

interface PlatformAdapter
{
    /**
     * Install a PHP version with FPM.
     */
    public function installPhp(string $version): bool;

    /**
     * Check if a PHP version is installed.
     */
    public function isPhpInstalled(string $version): bool;

    /**
     * Get all installed PHP versions.
     *
     * @return array<string>
     */
    public function getInstalledPhpVersions(): array;

    /**
     * Start PHP-FPM service for a version.
     */
    public function startPhpFpm(string $version): bool;

    /**
     * Stop PHP-FPM service for a version.
     */
    public function stopPhpFpm(string $version): bool;

    /**
     * Restart PHP-FPM service for a version.
     */
    public function restartPhpFpm(string $version): bool;

    /**
     * Gracefully reload PHP-FPM service for a version.
     */
    public function reloadPhpFpm(string $version): bool;

    /**
     * Check if PHP-FPM service is running for a version.
     */
    public function isPhpFpmRunning(string $version): bool;

    /**
     * Get the socket path for a PHP version.
     */
    public function getSocketPath(string $version): string;

    /**
     * Get the path to the PHP binary for a version.
     */
    public function getPhpBinaryPath(string $version): string;

    /**
     * Get the FPM pool configuration directory.
     */
    public function getPoolConfigDir(string $version): string;

    /**
     * Install Caddy web server.
     */
    public function installCaddy(): bool;

    /**
     * Check if Caddy is installed.
     */
    public function isCaddyInstalled(): bool;

    /**
     * Start Caddy service.
     */
    public function startCaddy(): bool;

    /**
     * Stop Caddy service.
     */
    public function stopCaddy(): bool;

    /**
     * Restart Caddy service.
     */
    public function restartCaddy(): bool;

    /**
     * Reload Caddy configuration without restarting.
     */
    public function reloadCaddy(): bool;

    /**
     * Check if Caddy service is running.
     */
    public function isCaddyRunning(): bool;

    /**
     * Get current user name.
     */
    public function getUser(): string;

    /**
     * Get current group name.
     */
    public function getGroup(): string;

    /**
     * Get home directory path.
     */
    public function getHomePath(): string;

    /**
     * Set the default PHP CLI version.
     */
    public function setDefaultPhpCli(string $version): bool;

    /**
     * Get the current default PHP CLI version.
     */
    public function getDefaultPhpCli(): ?string;
}
