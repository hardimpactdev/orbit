<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class MacPhpFpmService
{
    protected string $homeDir;

    protected string $username;

    protected string $configDir;

    protected string $socketDir;

    protected string $logDir;

    public function __construct()
    {
        $home = getenv('HOME');
        $serverHome = $_SERVER['HOME'] ?? null;
        $envHome = $_ENV['HOME'] ?? null;
        $this->homeDir = is_string($home) && $home !== '' ? $home : (is_string($serverHome) ? $serverHome : (is_string($envHome) ? $envHome : ''));

        $user = getenv('USER');
        $serverUser = $_SERVER['USER'] ?? null;
        $envUser = $_ENV['USER'] ?? null;
        $this->username = is_string($user) && $user !== '' ? $user : (is_string($serverUser) ? $serverUser : (is_string($envUser) ? $envUser : 'user'));

        $this->configDir = $this->homeDir.'/.config/launchpad/php';
        $this->socketDir = $this->homeDir.'/.config/launchpad/php';
        $this->logDir = $this->homeDir.'/.config/launchpad/logs';
    }

    /**
     * @return list<string>
     */
    public function detectInstalledPhpVersions(): array
    {
        $versions = [];

        $result = Process::run('ls -d /opt/homebrew/opt/php@* 2>/dev/null || true');

        if ($result->successful()) {
            $lines = array_filter(explode("\n", trim($result->output())));

            foreach ($lines as $path) {
                if (preg_match('/php@(\d+\.\d+)$/', $path, $matches)) {
                    $versions[] = $matches[1];
                }
            }
        }

        $defaultPhpResult = Process::run('ls -d /opt/homebrew/opt/php 2>/dev/null || true');
        if ($defaultPhpResult->successful() && trim($defaultPhpResult->output()) !== '') {
            $versionResult = Process::run('/opt/homebrew/opt/php/bin/php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"');
            if ($versionResult->successful()) {
                $defaultVersion = trim($versionResult->output());
                if (! in_array($defaultVersion, $versions)) {
                    $versions[] = $defaultVersion;
                }
            }
        }

        usort($versions, fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    public function generatePoolConfig(string $version): string
    {
        $normalized = str_replace('.', '', $version);
        $socketPath = $this->socketDir."/php{$normalized}.sock";
        $logPath = $this->logDir."/php{$normalized}-fpm.log";

        return <<<INI
[launchpad]
; Pool for Launchpad projects using PHP {$version}

user = {$this->username}
group = staff

listen = {$socketPath}
listen.owner = {$this->username}
listen.group = staff
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Logging
php_admin_value[error_log] = {$logPath}
php_admin_flag[log_errors] = on
catch_workers_output = yes
decorate_workers_output = no

; Environment variables
env[PATH] = /opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin
env[HOME] = {$this->homeDir}
env[USER] = {$this->username}
INI;
    }

    public function installPoolConfig(string $version): bool
    {
        if (! is_dir($this->configDir)) {
            mkdir($this->configDir, 0755, true);
        }

        if (! is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $normalized = str_replace('.', '', $version);
        $poolConfig = $this->generatePoolConfig($version);
        $poolConfigPath = $this->configDir."/php{$normalized}-fpm.conf";

        if (file_put_contents($poolConfigPath, $poolConfig) === false) {
            return false;
        }

        $fpmConfDir = "/opt/homebrew/etc/php/{$version}/php-fpm.d";

        if (! is_dir($fpmConfDir)) {
            return false;
        }

        $symlinkPath = "{$fpmConfDir}/launchpad.conf";

        if (is_link($symlinkPath) || file_exists($symlinkPath)) {
            unlink($symlinkPath);
        }

        return symlink($poolConfigPath, $symlinkPath);
    }

    public function uninstallPoolConfig(string $version): bool
    {
        $normalized = str_replace('.', '', $version);
        $poolConfigPath = $this->configDir."/php{$normalized}-fpm.conf";
        $symlinkPath = "/opt/homebrew/etc/php/{$version}/php-fpm.d/launchpad.conf";

        $success = true;

        if (is_link($symlinkPath) || file_exists($symlinkPath)) {
            $success = unlink($symlinkPath);
        }

        if (file_exists($poolConfigPath)) {
            $success = unlink($poolConfigPath) && $success;
        }

        return $success;
    }

    public function getSocketPath(string $version): string
    {
        $normalized = str_replace('.', '', $version);

        return $this->socketDir."/php{$normalized}.sock";
    }

    public function getConfigDir(): string
    {
        return $this->configDir;
    }

    public function getLogDir(): string
    {
        return $this->logDir;
    }

    public function getHomeDir(): string
    {
        return $this->homeDir;
    }

    public function getUsername(): string
    {
        return $this->username;
    }
}
