<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use Illuminate\Support\Facades\Process;

/**
 * Service for managing Horizon queue workers.
 * Handles both production (orbit-horizon) and dev (orbit-horizon-dev) instances.
 */
class HorizonService
{
    /**
     * Determine which horizon instance to use based on the app context.
     */
    public function isDevInstance(): bool
    {
        return str_contains(base_path(), '/projects/orbit-web');
    }

    /**
     * Get the systemd service name for the current context.
     */
    public function getServiceName(): string
    {
        return $this->isDevInstance() ? 'orbit-horizon-dev' : 'orbit-horizon';
    }

    /**
     * Get the service key used in the UI.
     */
    public function getServiceKey(): string
    {
        return $this->isDevInstance() ? 'horizon-dev' : 'horizon';
    }

    /**
     * Check if horizon is running.
     */
    public function isRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $serviceName = $this->getServiceName();
            $result = Process::run("systemctl is-active --quiet {$serviceName}");

            return $result->successful();
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS: check launchctl
            $label = $this->isDevInstance() ? 'com.orbit.horizon-dev' : 'com.orbit.horizon';
            $result = Process::run("launchctl list | grep -q {$label}");

            return $result->successful();
        }

        return false;
    }

    /**
     * Start horizon service.
     */
    public function start(): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $serviceName = $this->getServiceName();
            $result = Process::run("sudo systemctl start {$serviceName}");

            return $result->successful();
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $label = $this->isDevInstance() ? 'com.orbit.horizon-dev' : 'com.orbit.horizon';
            $result = Process::run("launchctl load ~/Library/LaunchAgents/{$label}.plist");

            return $result->successful();
        }

        return false;
    }

    /**
     * Stop horizon service.
     */
    public function stop(): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $serviceName = $this->getServiceName();
            $result = Process::run("sudo systemctl stop {$serviceName}");

            return $result->successful();
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $label = $this->isDevInstance() ? 'com.orbit.horizon-dev' : 'com.orbit.horizon';
            $result = Process::run("launchctl unload ~/Library/LaunchAgents/{$label}.plist");

            return $result->successful();
        }

        return false;
    }

    /**
     * Restart horizon service.
     */
    public function restart(): bool
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $serviceName = $this->getServiceName();
            $result = Process::run("sudo systemctl restart {$serviceName}");

            return $result->successful();
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->stop();

            return $this->start();
        }

        return false;
    }

    /**
     * Get horizon logs.
     */
    public function getLogs(int $lines = 100): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $serviceName = $this->getServiceName();
            $result = Process::run("journalctl -u {$serviceName} -n {$lines} --no-pager");

            return $result->output();
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS logs location
            $configPath = $_SERVER['HOME'].'/.config/orbit';
            $logPath = "{$configPath}/logs/horizon.log";
            if (file_exists($logPath)) {
                $result = Process::run("tail -n {$lines} {$logPath}");

                return $result->output();
            }

            return 'No logs found';
        }

        return 'Platform not supported';
    }

    /**
     * Get status info for the services list.
     */
    public function getStatusInfo(): array
    {
        $isRunning = $this->isRunning();

        return [
            'status' => $isRunning ? 'running' : 'stopped',
            'health' => $isRunning ? 'healthy' : null,
            'container' => null,
            'type' => 'systemd',
        ];
    }
}
