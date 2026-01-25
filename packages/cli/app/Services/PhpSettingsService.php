<?php

namespace App\Services;

use App\Services\Platform\PlatformAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PhpSettingsService
{
    protected PlatformAdapter $adapter;

    public function __construct(protected PhpManager $phpManager)
    {
        $this->adapter = $phpManager->getAdapter();
    }

    /**
     * Get current PHP settings for a version.
     */
    public function getSettings(string $version): array
    {
        $iniPath = $this->getPhpIniPath($version);
        $poolPath = $this->getPoolConfigPath($version);

        return [
            'upload_max_filesize' => $this->getIniValue($iniPath, 'upload_max_filesize', '2M'),
            'post_max_size' => $this->getIniValue($iniPath, 'post_max_size', '8M'),
            'memory_limit' => $this->getIniValue($iniPath, 'memory_limit', '128M'),
            'max_execution_time' => $this->getIniValue($iniPath, 'max_execution_time', '30'),
            'max_children' => $this->getPoolValue($poolPath, 'pm.max_children', '5'),
            'start_servers' => $this->getPoolValue($poolPath, 'pm.start_servers', '2'),
            'min_spare_servers' => $this->getPoolValue($poolPath, 'pm.min_spare_servers', '1'),
            'max_spare_servers' => $this->getPoolValue($poolPath, 'pm.max_spare_servers', '3'),
        ];
    }

    /**
     * Update PHP settings for a version.
     */
    public function updateSettings(string $version, array $settings): bool
    {
        $iniPath = $this->getPhpIniPath($version);
        $poolPath = $this->getPoolConfigPath($version);

        // PHP.ini settings
        $iniSettings = ['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time'];
        $iniUpdates = [];
        foreach ($iniSettings as $key) {
            if (isset($settings[$key])) {
                $iniUpdates[$key] = $settings[$key];
            }
        }

        // Pool settings
        $poolSettings = ['max_children', 'start_servers', 'min_spare_servers', 'max_spare_servers'];
        $poolUpdates = [];
        foreach ($poolSettings as $key) {
            if (isset($settings[$key])) {
                $poolKey = 'pm.'.$key;
                $poolUpdates[$poolKey] = $settings[$key];
            }
        }

        // Update php.ini
        if (! empty($iniUpdates)) {
            $this->updateIniFile($iniPath, $iniUpdates);
        }

        // Update pool config
        if (! empty($poolUpdates)) {
            $this->updatePoolFile($poolPath, $poolUpdates);
        }

        // Restart PHP-FPM to apply changes
        return $this->phpManager->restart($version);
    }

    /**
     * Get the php.ini path for FPM.
     */
    public function getPhpIniPath(string $version): string
    {
        $normalized = $this->normalizeVersion($version);

        // Linux
        if (File::exists("/etc/php/{$normalized}/fpm/php.ini")) {
            return "/etc/php/{$normalized}/fpm/php.ini";
        }

        // macOS Homebrew
        $homebrewPrefix = getenv('HOMEBREW_PREFIX') ?: '/opt/homebrew';
        if (File::exists("{$homebrewPrefix}/etc/php/{$normalized}/php.ini")) {
            return "{$homebrewPrefix}/etc/php/{$normalized}/php.ini";
        }

        return "/etc/php/{$normalized}/fpm/php.ini";
    }

    /**
     * Get the php-fpm.conf path.
     */
    public function getFpmConfPath(string $version): string
    {
        $normalized = $this->normalizeVersion($version);

        // Linux
        if (File::exists("/etc/php/{$normalized}/fpm/php-fpm.conf")) {
            return "/etc/php/{$normalized}/fpm/php-fpm.conf";
        }

        // macOS Homebrew
        $homebrewPrefix = getenv('HOMEBREW_PREFIX') ?: '/opt/homebrew';
        if (File::exists("{$homebrewPrefix}/etc/php/{$normalized}/php-fpm.conf")) {
            return "{$homebrewPrefix}/etc/php/{$normalized}/php-fpm.conf";
        }

        return "/etc/php/{$normalized}/fpm/php-fpm.conf";
    }

    /**
     * Get the pool configuration path.
     */
    public function getPoolConfigPath(string $version): string
    {
        return $this->phpManager->getPoolConfigPath($version);
    }

    /**
     * Get a value from an INI file.
     */
    protected function getIniValue(string $path, string $key, string $default = ''): string
    {
        if (! File::exists($path)) {
            return $default;
        }

        $content = File::get($path);

        // Match: key = value (with optional comments after)
        $pattern = '/^\s*'.preg_quote($key, '/').'\s*=\s*([^;\n]+)/m';
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return $default;
    }

    /**
     * Get a value from a pool config file.
     */
    protected function getPoolValue(string $path, string $key, string $default = ''): string
    {
        if (! File::exists($path)) {
            return $default;
        }

        $content = File::get($path);

        // Match: key = value
        $pattern = '/^\s*'.preg_quote($key, '/').'\s*=\s*(\S+)/m';
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return $default;
    }

    /**
     * Update values in an INI file (requires sudo for system files).
     */
    protected function updateIniFile(string $path, array $values): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $content = File::get($path);

        foreach ($values as $key => $value) {
            // Try to replace existing value
            $pattern = '/^(\s*)('.preg_quote((string) $key, '/').')\s*=\s*[^;\n]*/m';
            if (preg_match($pattern, (string) $content)) {
                $content = preg_replace($pattern, "$1$2 = {$value}", (string) $content);
            } else {
                // Add new value at the end
                $content .= "\n{$key} = {$value}\n";
            }
        }

        // Write using sudo for system files
        $tempFile = sys_get_temp_dir().'/php_ini_'.uniqid();
        File::put($tempFile, $content);

        $result = Process::run("sudo cp {$tempFile} {$path}");
        File::delete($tempFile);

        return $result->successful();
    }

    /**
     * Update values in a pool config file.
     */
    protected function updatePoolFile(string $path, array $values): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $content = File::get($path);

        foreach ($values as $key => $value) {
            // Try to replace existing value
            $pattern = '/^(\s*)('.preg_quote((string) $key, '/').')\s*=\s*\S+/m';
            if (preg_match($pattern, (string) $content)) {
                $content = preg_replace($pattern, "$1$2 = {$value}", (string) $content);
            } else {
                // Add new value at the end (before last line if exists)
                $content .= "\n{$key} = {$value}\n";
            }
        }

        // Write using sudo for system files
        $tempFile = sys_get_temp_dir().'/php_pool_'.uniqid();
        File::put($tempFile, $content);

        $result = Process::run("sudo cp {$tempFile} {$path}");
        File::delete($tempFile);

        return $result->successful();
    }

    /**
     * Normalize PHP version (8.4, php8.4, 84 -> 8.4).
     */
    protected function normalizeVersion(string $version): string
    {
        $version = str_replace(['php@', 'php'], '', $version);

        if (preg_match('/^\d+\.\d+$/', $version)) {
            return $version;
        }

        if (preg_match('/^\d{2}$/', $version)) {
            return substr($version, 0, 1).'.'.substr($version, 1);
        }

        return $version;
    }
}
