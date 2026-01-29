<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use Illuminate\Support\Facades\Process;

class MacPhpFpmConfigService
{
    public function ensureConfigured(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return ['success' => true, 'skipped' => true];
        }

        if (app()->runningUnitTests()) {
            return ['success' => true, 'skipped' => true];
        }

        $homebrewPrefix = $this->getHomebrewPrefix();
        if (! $homebrewPrefix) {
            return ['success' => true, 'skipped' => true];
        }

        $etcPhpPath = $homebrewPrefix.'/etc/php';
        if (! is_dir($etcPhpPath)) {
            return ['success' => true, 'skipped' => true];
        }

        $globalIniPath = $this->getGlobalIniPath();
        if (! $globalIniPath) {
            return ['success' => false, 'error' => 'Unable to determine home directory'];
        }

        $versions = $this->getInstalledPhpVersions($etcPhpPath);
        if ($versions === []) {
            return ['success' => true, 'skipped' => true];
        }

        $changedAny = false;

        $globalIniCreated = $this->ensureFileExists(
            $globalIniPath,
            "; Orbit global PHP settings\n"
            ."; Shared across all installed Homebrew PHP versions (CLI + FPM)\n"
            ."; Add directives here (e.g. memory_limit=512M)\n"
        );
        $changedAny = $changedAny || $globalIniCreated;

        foreach ($versions as $version) {
            $confDir = $etcPhpPath."/{$version}/conf.d";
            if (! is_dir($confDir) && ! @mkdir($confDir, 0755, true) && ! is_dir($confDir)) {
                continue;
            }

            $link = $confDir.'/99-orbit.ini';
            $changedAny = $changedAny || $this->ensureSymlink($link, $globalIniPath);
        }

        $watcherResult = $this->ensureLaunchAgent($homebrewPrefix, $etcPhpPath, $versions, $globalIniPath);
        if (! $watcherResult['success']) {
            return $watcherResult;
        }

        if ($changedAny) {
            foreach ($versions as $version) {
                $this->restartPhpFpm($homebrewPrefix, $version);
            }
        }

        return ['success' => true, 'changed' => $changedAny];
    }

    public function getGlobalIniPath(): ?string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? null);
        if (! $home) {
            return null;
        }

        return rtrim($home, '/').'/.config/orbit/php/orbit.ini';
    }

    public function getHomebrewPrefix(): ?string
    {
        if (is_dir('/opt/homebrew')) {
            return '/opt/homebrew';
        }

        if (is_dir('/usr/local')) {
            return '/usr/local';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function getInstalledPhpVersions(string $etcPhpPath): array
    {
        $entries = @scandir($etcPhpPath) ?: [];
        $versions = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! preg_match('/^\d+\.\d+$/', $entry)) {
                continue;
            }

            if (is_dir($etcPhpPath.'/'.$entry)) {
                $versions[] = $entry;
            }
        }

        usort($versions, version_compare(...));

        return $versions;
    }

    protected function ensureFileExists(string $path, string $contents): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        if (file_exists($path)) {
            return false;
        }

        return file_put_contents($path, $contents) !== false;
    }

    protected function ensureSymlink(string $link, string $target): bool
    {
        if (is_link($link)) {
            $current = readlink($link);
            if ($current === $target) {
                return false;
            }
        }

        if (file_exists($link) || is_link($link)) {
            @unlink($link);
        }

        return @symlink($target, $link);
    }

    protected function restartPhpFpm(string $homebrewPrefix, string $version): void
    {
        $brew = $homebrewPrefix.'/bin/brew';
        if (! is_file($brew)) {
            return;
        }

        $service = "php@{$version}";
        Process::timeout(30)->run([$brew, 'services', 'restart', $service]);
    }

    protected function ensureLaunchAgent(string $homebrewPrefix, string $etcPhpPath, array $versions, string $globalIniPath): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? null);
        if (! $home) {
            return ['success' => false, 'error' => 'Unable to determine home directory'];
        }

        $scriptPath = rtrim($home, '/').'/.config/orbit/php/reload-php-fpm.sh';
        $logDir = rtrim($home, '/').'/Library/Logs/orbit';
        $logPath = $logDir.'/php-fpm-reload.log';

        if (! is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $scriptLines = [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            "export PATH=\"{$homebrewPrefix}/bin:{$homebrewPrefix}/sbin:\$PATH\"",
            "LOG=\"{$logPath}\"",
            'date "+%Y-%m-%d %H:%M:%S" >> "$LOG"',
        ];

        foreach ($versions as $version) {
            $scriptLines[] = "{$homebrewPrefix}/bin/brew services restart php@{$version} >> \"\$LOG\" 2>&1 || true";
        }

        $script = implode("\n", $scriptLines)."\n";

        if (! is_dir(dirname($scriptPath))) {
            @mkdir(dirname($scriptPath), 0755, true);
        }

        if (! file_exists($scriptPath) || file_get_contents($scriptPath) !== $script) {
            if (file_put_contents($scriptPath, $script) === false) {
                return ['success' => false, 'error' => 'Failed to write PHP-FPM reload script'];
            }

            @chmod($scriptPath, 0755);
        }

        $launchAgentsDir = rtrim($home, '/').'/Library/LaunchAgents';
        if (! is_dir($launchAgentsDir) && ! @mkdir($launchAgentsDir, 0755, true) && ! is_dir($launchAgentsDir)) {
            return ['success' => false, 'error' => 'Failed to create LaunchAgents directory'];
        }

        $watchPaths = [
            $globalIniPath,
        ];

        foreach ($versions as $version) {
            $base = $etcPhpPath."/{$version}";
            $watchPaths[] = $base.'/php.ini';
            $watchPaths[] = $base.'/php-fpm.conf';
            $watchPaths[] = $base.'/conf.d';
            $watchPaths[] = $base.'/php-fpm.d';
        }

        $watchPaths = array_values(array_filter($watchPaths, fn ($path) => file_exists($path) || is_dir($path)));

        $watchPathsXml = '';
        foreach ($watchPaths as $path) {
            $watchPathsXml .= "        <string>{$path}</string>\n";
        }

        $plistPath = $launchAgentsDir.'/com.orbit.php-fpm-reload.plist';
        $plist = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.orbit.php-fpm-reload</string>

    <key>ProgramArguments</key>
    <array>
        <string>{$scriptPath}</string>
    </array>

    <key>WatchPaths</key>
    <array>
{$watchPathsXml}    </array>

    <key>ThrottleInterval</key>
    <integer>2</integer>

    <key>StandardOutPath</key>
    <string>{$logPath}</string>

    <key>StandardErrorPath</key>
    <string>{$logPath}</string>
</dict>
</plist>
PLIST;

        $plistChanged = ! file_exists($plistPath) || file_get_contents($plistPath) !== $plist;
        if ($plistChanged) {
            if (file_put_contents($plistPath, $plist) === false) {
                return ['success' => false, 'error' => 'Failed to write launchd plist'];
            }
        }

        $uid = (int) (trim((string) shell_exec('id -u')));
        if ($uid <= 0) {
            return ['success' => false, 'error' => 'Failed to determine user id'];
        }

        $serviceTarget = "gui/{$uid}/com.orbit.php-fpm-reload";
        $print = Process::run(['launchctl', 'print', $serviceTarget]);
        $needsReload = $plistChanged || ! $print->successful();

        if ($needsReload) {
            Process::run(['launchctl', 'bootout', $serviceTarget]);
            $bootstrap = Process::run(['launchctl', 'bootstrap', "gui/{$uid}", $plistPath]);

            if (! $bootstrap->successful()) {
                return ['success' => false, 'error' => $bootstrap->errorOutput() ?: 'Failed to load launchd agent'];
            }

            Process::run(['launchctl', 'enable', $serviceTarget]);
        }

        return ['success' => true];
    }
}
