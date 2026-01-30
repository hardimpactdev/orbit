<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Process;

class CaddyManager
{
    public function __construct(
        protected ConfigManager $configManager,
        protected PhpManager $phpManager
    ) {}

    /**
     * Install Caddy web server.
     */
    public function install(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->installCaddy();
    }

    /**
     * Check if Caddy is installed.
     */
    public function isInstalled(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->isCaddyInstalled();
    }

    /**
     * Start Caddy service.
     */
    public function start(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->startCaddy();
    }

    /**
     * Stop Caddy service.
     */
    public function stop(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->stopCaddy();
    }

    /**
     * Restart Caddy service.
     */
    public function restart(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->restartCaddy();
    }

    /**
     * Reload Caddy configuration without restarting.
     */
    public function reload(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->reloadCaddy();
    }

    /**
     * Check if Caddy service is running.
     */
    public function isRunning(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        return $adapter->isCaddyRunning();
    }

    /**
     * Get the Caddyfile path.
     */
    public function getCaddyfilePath(): string
    {
        return $this->configManager->getConfigPath().'/caddy/Caddyfile';
    }

    /**
     * Validate Caddy configuration.
     */
    public function validateConfig(): bool
    {
        $caddyfilePath = $this->getCaddyfilePath();

        // Run caddy validate
        $result = Process::run("caddy validate --config {$caddyfilePath}");

        return $result->successful();
    }

    /**
     * Get Caddy data directory path.
     */
    public function getDataPath(): string
    {
        return $this->configManager->getConfigPath().'/caddy/data';
    }

    /**
     * Get Caddy config directory path.
     */
    public function getConfigPath(): string
    {
        return $this->configManager->getConfigPath().'/caddy';
    }
}
