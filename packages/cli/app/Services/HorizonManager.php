<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class HorizonManager
{
    protected const SYSTEMD_SERVICE_NAME = 'orbit-horizon';

    protected const LAUNCHD_LABEL = 'com.orbit.horizon';

    public function __construct(
        protected ConfigManager $configManager,
        protected PhpManager $phpManager
    ) {}

    /**
     * Install Horizon as a system service.
     */
    public function install(): bool
    {
        $adapter = $this->phpManager->getAdapter();

        if ($this->isLinux()) {
            return $this->installSystemdService();
        }

        if ($this->isMac()) {
            return $this->installLaunchdService();
        }

        throw new \RuntimeException('Unsupported platform for Horizon service installation');
    }

    /**
     * Start Horizon service.
     */
    public function start(): bool
    {
        if ($this->isLinux()) {
            $result = Process::run('sudo systemctl start '.self::SYSTEMD_SERVICE_NAME);

            return $result->successful();
        }

        if ($this->isMac()) {
            $result = Process::run('launchctl load ~/Library/LaunchAgents/'.self::LAUNCHD_LABEL.'.plist');

            return $result->successful();
        }

        return false;
    }

    /**
     * Stop Horizon service.
     */
    public function stop(): bool
    {
        if ($this->isLinux()) {
            $result = Process::run('sudo systemctl stop '.self::SYSTEMD_SERVICE_NAME);

            return $result->successful();
        }

        if ($this->isMac()) {
            $result = Process::run('launchctl unload ~/Library/LaunchAgents/'.self::LAUNCHD_LABEL.'.plist');

            return $result->successful();
        }

        return false;
    }

    /**
     * Restart Horizon service.
     */
    public function restart(): bool
    {
        if ($this->isLinux()) {
            $result = Process::run('sudo systemctl restart '.self::SYSTEMD_SERVICE_NAME);

            return $result->successful();
        }

        if ($this->isMac()) {
            $this->stop();

            return $this->start();
        }

        return false;
    }

    /**
     * Check if Horizon service is running.
     */
    public function isRunning(): bool
    {
        if ($this->isLinux()) {
            $result = Process::run('systemctl is-active --quiet '.self::SYSTEMD_SERVICE_NAME);

            return $result->successful();
        }

        if ($this->isMac()) {
            $result = Process::run('launchctl list | grep -q '.self::LAUNCHD_LABEL);

            return $result->successful();
        }

        return false;
    }

    /**
     * Get Horizon logs.
     */
    public function getLogs(int $lines = 100): string
    {
        if ($this->isLinux()) {
            $result = Process::run('journalctl -u '.self::SYSTEMD_SERVICE_NAME." -n {$lines} --no-pager");

            return $result->output();
        }

        if ($this->isMac()) {
            // On macOS, logs go to the path specified in the plist
            $logPath = $this->getLogPath();
            if (File::exists($logPath)) {
                $result = Process::run("tail -n {$lines} {$logPath}");

                return $result->output();
            }

            return 'No logs found';
        }

        return 'Platform not supported';
    }

    /**
     * Check if Horizon service is installed.
     */
    public function isInstalled(): bool
    {
        if ($this->isLinux()) {
            $servicePath = '/etc/systemd/system/'.self::SYSTEMD_SERVICE_NAME.'.service';

            return File::exists($servicePath);
        }

        if ($this->isMac()) {
            $plistPath = $this->getPlistPath();

            return File::exists($plistPath);
        }

        return false;
    }

    /**
     * Uninstall Horizon service.
     */
    public function uninstall(): bool
    {
        // Stop the service first
        $this->stop();

        if ($this->isLinux()) {
            $servicePath = '/etc/systemd/system/'.self::SYSTEMD_SERVICE_NAME.'.service';
            Process::run("sudo rm {$servicePath}");
            Process::run('sudo systemctl daemon-reload');

            return true;
        }

        if ($this->isMac()) {
            $plistPath = $this->getPlistPath();
            File::delete($plistPath);

            return true;
        }

        return false;
    }

    /**
     * Install systemd service on Linux.
     */
    protected function installSystemdService(): bool
    {
        // Load stub template
        $stub = File::get($this->stubPath('horizon-systemd.service.stub'));

        // Get default PHP version
        $phpVersion = $this->configManager->getDefaultPhpVersion();

        // Replace placeholders
        $service = str_replace([
            'ORBIT_USER',
            'ORBIT_GROUP',
            'ORBIT_WEB_PATH',
            'ORBIT_PHP_BIN',
            'ORBIT_ENV_PATH',
        ], [
            $this->phpManager->getAdapter()->getUser(),
            $this->phpManager->getAdapter()->getGroup(),
            $this->configManager->getWebAppPath(),
            trim(shell_exec('which php')),
            $this->phpManager->getEnvPath(),
        ], $stub);

        // Write service file
        $servicePath = '/etc/systemd/system/'.self::SYSTEMD_SERVICE_NAME.'.service';
        $tmpPath = '/tmp/'.self::SYSTEMD_SERVICE_NAME.'.service';
        File::put($tmpPath, $service);

        // Move to systemd directory
        Process::run("sudo mv {$tmpPath} {$servicePath}");
        Process::run("sudo chmod 644 {$servicePath}");

        // Reload systemd and enable service
        Process::run('sudo systemctl daemon-reload');
        $result = Process::run('sudo systemctl enable '.self::SYSTEMD_SERVICE_NAME);

        return $result->successful();
    }

    /**
     * Install launchd service on macOS.
     */
    protected function installLaunchdService(): bool
    {
        // Load stub template
        $stub = File::get($this->stubPath('horizon-launchd.plist.stub'));

        // Get default PHP version
        $phpVersion = $this->configManager->getDefaultPhpVersion();

        // Replace placeholders
        $plist = str_replace([
            'ORBIT_WEB_PATH',
            'ORBIT_PHP_BIN',
            'ORBIT_ENV_PATH',
            'ORBIT_LOG_PATH',
        ], [
            $this->configManager->getWebAppPath(),
            trim(shell_exec('which php')),
            $this->phpManager->getEnvPath(),
            $this->getLogPath(),
        ], $stub);

        // Ensure LaunchAgents directory exists
        $launchAgentsDir = $this->phpManager->getAdapter()->getHomePath().'/Library/LaunchAgents';
        if (! File::isDirectory($launchAgentsDir)) {
            File::makeDirectory($launchAgentsDir, 0755, true);
        }

        // Write plist file
        $plistPath = $this->getPlistPath();
        File::put($plistPath, $plist);

        return true;
    }

    /**
     * Get the launchd plist path.
     */
    protected function getPlistPath(): string
    {
        $home = $this->phpManager->getAdapter()->getHomePath();

        return "{$home}/Library/LaunchAgents/".self::LAUNCHD_LABEL.'.plist';
    }

    /**
     * Get the log path for Horizon.
     */
    protected function getLogPath(): string
    {
        $logDir = $this->configManager->getConfigPath().'/logs';

        if (! File::isDirectory($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        return "{$logDir}/horizon.log";
    }

    /**
     * Get the stub file path.
     */
    protected function stubPath(string $stub): string
    {
        return __DIR__.'/../../stubs/'.$stub;
    }

    /**
     * Check if running on Linux.
     */
    protected function isLinux(): bool
    {
        return stripos(php_uname('s'), 'Linux') !== false;
    }

    /**
     * Check if running on macOS.
     */
    protected function isMac(): bool
    {
        return stripos(php_uname('s'), 'Darwin') !== false;
    }
}
