<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Process;

class LaunchpadService
{
    // Common installation paths for launchpad on remote servers
    // Development paths first to prefer newer code over installed PHAR
    protected array $binaryPaths = [
        '$HOME/projects/launchpad-cli/launchpad',
        '$HOME/projects/launchpad/launchpad',
        '$HOME/.local/bin/launchpad',
        '/usr/local/bin/launchpad',
        '$HOME/.composer/vendor/bin/launchpad',
    ];

    public function __construct(protected SshService $ssh, protected CliUpdateService $cliUpdate) {}

    protected function findBinary(Server $server): ?string
    {
        // Check common locations including project directory
        // Development paths first to prefer newer code over installed PHAR
        $paths = [
            '$HOME/projects/launchpad-cli/launchpad',
            '$HOME/projects/launchpad/launchpad',
            '$HOME/.local/bin/launchpad',
            '/usr/local/bin/launchpad',
            '$HOME/.composer/vendor/bin/launchpad',
        ];

        foreach ($paths as $path) {
            $result = $this->ssh->execute($server, "test -x {$path} && echo {$path}");
            if ($result['success'] && ! in_array(trim((string) $result['output']), ['', '0'], true)) {
                return trim((string) $result['output']);
            }
        }

        // Fallback: try which with extended PATH
        $result = $this->ssh->execute(
            $server,
            'export PATH="$HOME/projects/launchpad-cli:$HOME/projects/launchpad:$HOME/.local/bin:$HOME/.composer/vendor/bin:/usr/local/bin:$PATH" && which launchpad'
        );

        if ($result['success'] && ! in_array(trim((string) $result['output']), ['', '0'], true)) {
            return trim((string) $result['output']);
        }

        return null;
    }

    public function status(Server $server): array
    {
        return $this->executeCommand($server, 'status --json');
    }

    public function sites(Server $server): array
    {
        return $this->executeCommand($server, 'sites --json');
    }

    public function start(Server $server, ?string $site = null): array
    {
        $command = $site ? "start {$site} --json" : 'start --json';

        return $this->executeCommand($server, $command);
    }

    public function stop(Server $server, ?string $site = null): array
    {
        $command = $site ? "stop {$site} --json" : 'stop --json';

        return $this->executeCommand($server, $command);
    }

    public function restart(Server $server, ?string $site = null): array
    {
        $command = $site ? "restart {$site} --json" : 'restart --json';

        return $this->executeCommand($server, $command);
    }

    public function php(Server $server, string $site, ?string $version = null): array
    {
        $command = $version
            ? "php {$site} {$version} --json"
            : "php {$site} --json";

        return $this->executeCommand($server, $command);
    }

    /**
     * Rebuild the DNS container with the correct TLD and HOST_IP.
     * This is needed when TLD changes on a remote server.
     * Also restarts launchpad to regenerate Caddy config with new domains.
     */
    public function rebuildDns(Server $server, string $tld): array
    {
        // For local servers, just restart launchpad (handles DNS automatically)
        if ($server->is_local) {
            return $this->restartWithoutJson($server);
        }

        // For remote servers, rebuild the DNS container with correct TLD and HOST_IP
        $hostIp = $server->host;
        $escapedTld = escapeshellarg($tld);
        $escapedHostIp = escapeshellarg($hostIp);

        // Stop and remove existing DNS container
        $this->ssh->execute($server, 'sg docker -c "docker stop launchpad-dns 2>/dev/null || true"');
        $this->ssh->execute($server, 'sg docker -c "docker rm launchpad-dns 2>/dev/null || true"');

        // Rebuild DNS image with correct TLD and HOST_IP
        $buildCommand = "sg docker -c 'cd ~/.config/launchpad/dns && TLD={$escapedTld} HOST_IP={$escapedHostIp} docker compose build --no-cache'";
        $buildResult = $this->ssh->execute($server, $buildCommand, 120); // 2 min timeout for build

        if (! $buildResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to rebuild DNS container: '.($buildResult['error'] ?? 'Unknown error'),
            ];
        }

        // Restart launchpad to regenerate all configs (Caddy, etc.) with new TLD
        // This also starts the DNS container with the rebuilt image
        // Use restartWithoutJson to avoid JSON parsing errors from launchpad output
        $restartResult = $this->restartWithoutJson($server);

        if (! $restartResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to restart launchpad after DNS rebuild: '.($restartResult['error'] ?? 'Unknown error'),
            ];
        }

        return [
            'success' => true,
            'message' => "DNS rebuilt and launchpad restarted with TLD={$tld}",
        ];
    }

    /**
     * Restart launchpad without expecting JSON output.
     * Used by rebuildDns where we just need success/failure, not parsed data.
     */
    protected function restartWithoutJson(Server $server): array
    {
        if ($server->is_local) {
            if (! $this->cliUpdate->isInstalled()) {
                return ['success' => false, 'error' => 'Launchpad CLI not installed.'];
            }

            $pharPath = $this->cliUpdate->getPharPath();
            $result = Process::timeout(120)->run("php {$pharPath} restart");

            return [
                'success' => $result->successful(),
                'error' => $result->successful() ? null : ($result->errorOutput() ?: 'Command failed'),
            ];
        }

        // Remote server
        $path = $this->findBinary($server);
        if (! $path) {
            return ['success' => false, 'error' => 'Launchpad CLI not found on remote server'];
        }

        $result = $this->ssh->execute($server, "{$path} restart", 120);

        return [
            'success' => $result['success'],
            'error' => $result['success'] ? null : ($result['error'] ?? 'Command failed'),
        ];
    }

    public function phpReset(Server $server, string $site): array
    {
        return $this->executeCommand($server, "php {$site} --reset --json");
    }

    /**
     * Get all worktrees for a server (optionally filtered by site).
     */
    public function worktrees(Server $server, ?string $site = null): array
    {
        $command = $site
            ? "worktrees {$site} --json"
            : 'worktrees --json';

        return $this->executeCommand($server, $command);
    }

    /**
     * Unlink a worktree from a site.
     */
    public function unlinkWorktree(Server $server, string $site, string $worktreeName): array
    {
        $escapedSite = escapeshellarg($site);
        $escapedWorktree = escapeshellarg($worktreeName);

        return $this->executeCommand($server, "worktree:unlink {$escapedSite} {$escapedWorktree} --json");
    }

    /**
     * Refresh worktree detection (re-scan and auto-link new worktrees).
     */
    public function refreshWorktrees(Server $server): array
    {
        return $this->executeCommand($server, 'worktree:refresh --json');
    }

    public function checkInstallation(Server $server): array
    {
        // For local servers, check the bundled CLI
        if ($server->is_local) {
            return $this->checkLocalInstallation();
        }

        // For remote servers, check via SSH
        return $this->checkRemoteInstallation($server);
    }

    protected function checkLocalInstallation(): array
    {
        if (! $this->cliUpdate->isInstalled()) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $pharPath = $this->cliUpdate->getPharPath();
        $result = Process::timeout(10)->run("php {$pharPath} --version");

        return [
            'installed' => true,
            'path' => $pharPath,
            'version' => trim($result->output()),
        ];
    }

    protected function checkRemoteInstallation(Server $server): array
    {
        $path = $this->findBinary($server);

        if (! $path) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $versionResult = $this->ssh->execute($server, "{$path} --version");

        return [
            'installed' => true,
            'path' => $path,
            'version' => trim((string) $versionResult['output']),
        ];
    }

    protected function executeCommand(Server $server, string $command): array
    {
        // For local servers, use the bundled CLI directly
        if ($server->is_local) {
            return $this->executeLocalCommand($command);
        }

        // For remote servers, use SSH
        return $this->executeRemoteCommand($server, $command);
    }

    protected function executeLocalCommand(string $command): array
    {
        if (! $this->cliUpdate->isInstalled()) {
            return [
                'success' => false,
                'error' => 'Launchpad CLI not installed.',
                'exit_code' => 1,
            ];
        }

        $pharPath = $this->cliUpdate->getPharPath();
        $fullCommand = "php {$pharPath} {$command}";

        try {
            $result = Process::timeout(60)->run($fullCommand);

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'error' => $result->errorOutput() ?: 'Command failed',
                    'exit_code' => $result->exitCode(),
                ];
            }

            $decoded = json_decode($result->output(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'Failed to parse JSON: '.json_last_error_msg(),
                    'exit_code' => $result->exitCode(),
                ];
            }

            return $decoded;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Command timed out or failed: '.$e->getMessage(),
                'exit_code' => 1,
            ];
        }
    }

    protected function executeRemoteCommand(Server $server, string $command): array
    {
        $path = $this->findBinary($server);

        if (! $path) {
            return [
                'success' => false,
                'error' => 'Launchpad CLI not found on remote server',
                'exit_code' => 1,
            ];
        }

        $fullCommand = "{$path} {$command}";
        $result = $this->ssh->executeJson($server, $fullCommand);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Command failed',
                'exit_code' => $result['exit_code'] ?? 1,
            ];
        }

        // CLI returns {success: bool, data: {...}} - return it directly
        return $result['data'];
    }

    public function getCliStatus(): array
    {
        return $this->cliUpdate->getStatus();
    }

    public function updateCli(): array
    {
        return $this->cliUpdate->checkAndUpdate();
    }

    public function getConfig(Server $server): array
    {
        if ($server->is_local) {
            return $this->getLocalConfig();
        }

        return $this->getRemoteConfig($server);
    }

    protected function getLocalConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/launchpad/config.json';

        if (! file_exists($configPath)) {
            return [
                'success' => true,
                'data' => $this->getDefaultConfig(),
                'exists' => false,
            ];
        }

        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Failed to parse config: '.json_last_error_msg(),
            ];
        }

        return [
            'success' => true,
            'data' => array_merge($this->getDefaultConfig(), $config),
            'exists' => true,
        ];
    }

    protected function getRemoteConfig(Server $server): array
    {
        $result = $this->ssh->execute($server, 'cat ~/.config/launchpad/config.json 2>/dev/null');

        if (! $result['success'] || in_array(trim((string) $result['output']), ['', '0'], true)) {
            return [
                'success' => true,
                'data' => $this->getDefaultConfig(),
                'exists' => false,
            ];
        }

        $config = json_decode((string) $result['output'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Failed to parse config: '.json_last_error_msg(),
            ];
        }

        return [
            'success' => true,
            'data' => array_merge($this->getDefaultConfig(), $config),
            'exists' => true,
        ];
    }

    public function saveConfig(Server $server, array $config): array
    {
        if ($server->is_local) {
            return $this->saveLocalConfig($config);
        }

        return $this->saveRemoteConfig($server, $config);
    }

    protected function saveLocalConfig(array $config): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configDir = $home.'/.config/launchpad';
        $configPath = $configDir.'/config.json';

        // Ensure directory exists
        if (! is_dir($configDir) && ! mkdir($configDir, 0755, true)) {
            return [
                'success' => false,
                'error' => 'Failed to create config directory',
            ];
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($configPath, $json) === false) {
            return [
                'success' => false,
                'error' => 'Failed to write config file',
            ];
        }

        return [
            'success' => true,
            'data' => $config,
        ];
    }

    protected function saveRemoteConfig(Server $server, array $config): array
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $escapedJson = escapeshellarg($json);

        // Ensure directory exists and write file
        $command = "mkdir -p ~/.config/launchpad && echo {$escapedJson} > ~/.config/launchpad/config.json";
        $result = $this->ssh->execute($server, $command);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to save config',
            ];
        }

        return [
            'success' => true,
            'data' => $config,
        ];
    }

    protected function getDefaultConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '/home/user');

        return [
            'paths' => [$home.'/projects'],
            'tld' => 'test',
            'default_php_version' => '8.4',
        ];
    }
}
