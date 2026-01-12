<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class MacHorizonService
{
    protected string $homeDir;

    protected string $configPath;

    protected string $plistPath;

    protected string $plistLabel = 'com.launchpad.horizon';

    public function __construct()
    {
        $home = getenv('HOME');
        $this->homeDir = is_string($home) && $home !== '' ? $home : '/Users';
        $this->configPath = $this->homeDir.'/.config/launchpad';
        $this->plistPath = $this->homeDir.'/Library/LaunchAgents/'.$this->plistLabel.'.plist';
    }

    /**
     * Generate a launchd plist for Horizon.
     */
    public function generatePlist(string $phpVersion = '8.4'): string
    {
        $phpPath = "/opt/homebrew/opt/php@{$phpVersion}/bin/php";
        $artisanPath = $this->configPath.'/web/artisan';
        $logPath = $this->configPath.'/logs/horizon.log';
        $errorLogPath = $this->configPath.'/logs/horizon-error.log';
        $workingDirectory = $this->configPath.'/web';

        $home = getenv('HOME');
        $serverHome = $_SERVER['HOME'] ?? null;
        $envHome = $_ENV['HOME'] ?? null;
        $homeValue = is_string($home) && $home !== '' ? $home : (is_string($serverHome) ? $serverHome : (is_string($envHome) ? $envHome : ''));

        $user = getenv('USER');
        $serverUser = $_SERVER['USER'] ?? null;
        $envUser = $_ENV['USER'] ?? null;
        $username = is_string($user) && $user !== '' ? $user : (is_string($serverUser) ? $serverUser : (is_string($envUser) ? $envUser : 'user'));

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$this->plistLabel}</string>

    <key>ProgramArguments</key>
    <array>
        <string>{$phpPath}</string>
        <string>{$artisanPath}</string>
        <string>horizon</string>
    </array>

    <key>WorkingDirectory</key>
    <string>{$workingDirectory}</string>

    <key>EnvironmentVariables</key>
    <dict>
        <key>HOME</key>
        <string>{$homeValue}</string>
        <key>USER</key>
        <string>{$username}</string>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>

    <key>StandardOutPath</key>
    <string>{$logPath}</string>

    <key>StandardErrorPath</key>
    <string>{$errorLogPath}</string>

    <key>RunAtLoad</key>
    <true/>

    <key>KeepAlive</key>
    <true/>

    <key>ThrottleInterval</key>
    <integer>10</integer>
</dict>
</plist>
XML;
    }

    /**
     * Install the Horizon plist and load it with launchctl.
     */
    public function install(string $phpVersion = '8.4'): bool
    {
        // Ensure log directory exists
        $logDir = $this->configPath.'/logs';
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Ensure LaunchAgents directory exists
        $launchAgentsDir = dirname($this->plistPath);
        if (! is_dir($launchAgentsDir)) {
            mkdir($launchAgentsDir, 0755, true);
        }

        // Unload first if already loaded
        if ($this->isLoaded()) {
            $this->unload();
        }

        // Generate and write plist
        $plist = $this->generatePlist($phpVersion);
        if (file_put_contents($this->plistPath, $plist) === false) {
            return false;
        }

        // Load the plist
        return $this->load();
    }

    /**
     * Uninstall the Horizon plist.
     */
    public function uninstall(): bool
    {
        // Unload first
        $this->unload();

        // Remove plist file
        if (file_exists($this->plistPath)) {
            return unlink($this->plistPath);
        }

        return true;
    }

    /**
     * Load the plist with launchctl.
     */
    public function load(): bool
    {
        $result = Process::run("launchctl load -w {$this->plistPath}");

        return $result->successful();
    }

    /**
     * Unload the plist with launchctl.
     */
    public function unload(): bool
    {
        $result = Process::run("launchctl unload {$this->plistPath} 2>/dev/null");

        return $result->successful();
    }

    /**
     * Check if the Horizon service is loaded in launchctl.
     */
    public function isLoaded(): bool
    {
        $result = Process::run('launchctl list');

        if (! $result->successful()) {
            return false;
        }

        return str_contains($result->output(), $this->plistLabel);
    }

    /**
     * Check if the Horizon service is running.
     */
    public function isRunning(): bool
    {
        $result = Process::run("launchctl list {$this->plistLabel} 2>/dev/null");

        if (! $result->successful()) {
            return false;
        }

        // If the process has a PID (not "-"), it's running
        $output = $result->output();

        // Output format: "PID\tStatus\tLabel" or "-\tStatus\tLabel"
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (str_contains($line, $this->plistLabel)) {
                $parts = preg_split('/\s+/', $line);
                if ($parts !== false && isset($parts[0])) {
                    return $parts[0] !== '-' && is_numeric($parts[0]);
                }
            }
        }

        return false;
    }

    /**
     * Start the Horizon service.
     */
    public function start(): bool
    {
        if (! $this->isLoaded()) {
            return $this->load();
        }

        $result = Process::run("launchctl start {$this->plistLabel}");

        return $result->successful();
    }

    /**
     * Stop the Horizon service.
     */
    public function stop(): bool
    {
        $result = Process::run("launchctl stop {$this->plistLabel}");

        return $result->successful();
    }

    /**
     * Restart the Horizon service.
     */
    public function restart(): bool
    {
        $this->stop();
        sleep(1);

        return $this->start();
    }

    /**
     * Get the plist path.
     */
    public function getPlistPath(): string
    {
        return $this->plistPath;
    }

    /**
     * Get the log path.
     */
    public function getLogPath(): string
    {
        return $this->configPath.'/logs/horizon.log';
    }
}
