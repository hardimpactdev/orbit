<?php

namespace App\Services;

use App\Http\Integrations\Launchpad\LaunchpadConnector;
use App\Http\Integrations\Launchpad\Requests\AddWorkspaceProjectRequest;
use App\Http\Integrations\Launchpad\Requests\CreateProjectRequest;
use App\Http\Integrations\Launchpad\Requests\CreateWorkspaceRequest;
use App\Http\Integrations\Launchpad\Requests\DeleteProjectRequest;
use App\Http\Integrations\Launchpad\Requests\DeleteWorkspaceRequest;
use App\Http\Integrations\Launchpad\Requests\GetConfigRequest;
use App\Http\Integrations\Launchpad\Requests\GetLinkedPackagesRequest;
use App\Http\Integrations\Launchpad\Requests\GetPhpRequest;
use App\Http\Integrations\Launchpad\Requests\GetPhpVersionsRequest;
use App\Http\Integrations\Launchpad\Requests\GetProjectsRequest;
use App\Http\Integrations\Launchpad\Requests\GetProvisionStatusRequest;
use App\Http\Integrations\Launchpad\Requests\GetServiceLogsRequest;
use App\Http\Integrations\Launchpad\Requests\GetSitesRequest;
use App\Http\Integrations\Launchpad\Requests\GetStatusRequest;
use App\Http\Integrations\Launchpad\Requests\GetWorkspacesRequest;
use App\Http\Integrations\Launchpad\Requests\GetWorktreesRequest;
use App\Http\Integrations\Launchpad\Requests\LinkPackageRequest;
use App\Http\Integrations\Launchpad\Requests\RebuildProjectRequest;
use App\Http\Integrations\Launchpad\Requests\RefreshWorktreesRequest;
use App\Http\Integrations\Launchpad\Requests\RemoveWorkspaceProjectRequest;
use App\Http\Integrations\Launchpad\Requests\ResetPhpRequest;
use App\Http\Integrations\Launchpad\Requests\RestartServiceRequest;
use App\Http\Integrations\Launchpad\Requests\RestartServicesRequest;
use App\Http\Integrations\Launchpad\Requests\SetPhpRequest;
use App\Http\Integrations\Launchpad\Requests\StartServiceRequest;
use App\Http\Integrations\Launchpad\Requests\StartServicesRequest;
use App\Http\Integrations\Launchpad\Requests\StopServiceRequest;
use App\Http\Integrations\Launchpad\Requests\StopServicesRequest;
use App\Http\Integrations\Launchpad\Requests\UnlinkPackageRequest;
use App\Http\Integrations\Launchpad\Requests\UnlinkWorktreeRequest;
use App\Models\Environment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Request;

class LaunchpadService
{
    // Common installation paths for launchpad on remote servers
    // Installed phar first, then dev paths as fallback
    protected array $binaryPaths = [
        '$HOME/.local/bin/launchpad',
        '/usr/local/bin/launchpad',
        '$HOME/projects/launchpad-cli/launchpad',
        '$HOME/projects/launchpad/launchpad',
        '$HOME/.composer/vendor/bin/launchpad',
    ];

    public function __construct(protected SshService $ssh, protected CliUpdateService $cliUpdate) {}

    /**
     * Get the TLD for an environment.
     * Uses cached value from database or fetches via SSH on first request.
     */
    protected function getTld(Environment $environment): string
    {
        // Use cached TLD if available
        if ($environment->tld) {
            return $environment->tld;
        }

        // For local environments, read from local config
        if ($environment->is_local) {
            $config = $this->getLocalConfig();
            $tld = $config['data']['tld'] ?? 'test';
            $environment->update(['tld' => $tld]);

            return $tld;
        }

        // Bootstrap: fetch via SSH and cache (one-time only)
        $result = $this->ssh->execute($environment, 'cat ~/.config/launchpad/config.json 2>/dev/null');
        if ($result['success'] && ! empty($result['output'])) {
            $config = json_decode((string) $result['output'], true);
            $tld = $config['tld'] ?? 'test';
        } else {
            $tld = 'test';
        }

        $environment->update(['tld' => $tld]);

        return $tld;
    }

    /**
     * Get the Saloon connector for the launchpad web app API.
     */
    protected function getConnector(Environment $environment, int $timeout = 30): LaunchpadConnector
    {
        $tld = $this->getTld($environment);

        return LaunchpadConnector::forEnvironment($tld, $timeout);
    }

    /**
     * Send a Saloon request and return the result as an array.
     */
    protected function sendRequest(Environment $environment, Request $request): array
    {
        try {
            $connector = $this->getConnector($environment);
            $response = $connector->send($request);

            if ($response->successful()) {
                return $response->json() ?? ['success' => true];
            }

            return [
                'success' => false,
                'error' => $response->json('error') ?? 'Request failed with status '.$response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    protected function findBinary(Environment $environment): ?string
    {
        // Single SSH call to find launchpad binary
        // Check paths in order of preference (installed phar first)
        $command = <<<'BASH'
for path in "$HOME/.local/bin/launchpad" "/usr/local/bin/launchpad" "$HOME/projects/launchpad-cli/launchpad" "$HOME/projects/launchpad/launchpad" "$HOME/.composer/vendor/bin/launchpad"; do
    if [ -x "$path" ]; then echo "$path"; exit 0; fi
done
exit 1
BASH;

        $result = $this->ssh->execute($environment, $command);
        if ($result['success'] && ! in_array(trim((string) $result['output']), ['', '0'], true)) {
            return trim((string) $result['output']);
        }

        return null;
    }

    public function status(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'status --json');
        }

        $result = $this->sendRequest($environment, new GetStatusRequest);

        // Cache CLI info from status response (so checkInstallation can use it)
        if ($result['success'] && isset($result['data']['cli_version'])) {
            $environment->updateCliCache(
                $result['data']['cli_version'],
                $result['data']['cli_path'] ?? null
            );
        }

        return $result;
    }

    public function sites(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'sites --json');
        }

        return $this->sendRequest($environment, new GetSitesRequest);
    }

    /**
     * Get all projects from CLI (fresh, no caching).
     * Returns all directories in scan paths, with has_public_folder flag.
     */
    public function projectList(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'project:list --json');
        }

        return $this->sendRequest($environment, new GetProjectsRequest);
    }

    public function start(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "start {$site} --json" : 'start --json';

            return $this->executeCommand($environment, $command);
        }

        // Note: Site-specific start not supported via API yet
        return $this->sendRequest($environment, new StartServicesRequest);
    }

    public function stop(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "stop {$site} --json" : 'stop --json';

            return $this->executeCommand($environment, $command);
        }

        return $this->sendRequest($environment, new StopServicesRequest);
    }

    public function restart(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "restart {$site} --json" : 'restart --json';

            return $this->executeCommand($environment, $command);
        }

        return $this->sendRequest($environment, new RestartServicesRequest);
    }

    /**
     * Start a single service via Docker.
     */
    public function startService(Environment $environment, string $service): array
    {
        $container = $this->getContainerName($service);

        if ($environment->is_local) {
            return $this->dockerServiceAction($environment, $container, 'start');
        }

        return $this->sendRequest($environment, new StartServiceRequest($container));
    }

    /**
     * Stop a single service via Docker.
     */
    public function stopService(Environment $environment, string $service): array
    {
        $container = $this->getContainerName($service);

        if ($environment->is_local) {
            return $this->dockerServiceAction($environment, $container, 'stop');
        }

        return $this->sendRequest($environment, new StopServiceRequest($container));
    }

    /**
     * Restart a single service via Docker.
     */
    public function restartService(Environment $environment, string $service): array
    {
        $container = $this->getContainerName($service);

        if ($environment->is_local) {
            return $this->dockerServiceAction($environment, $container, 'restart');
        }

        return $this->sendRequest($environment, new RestartServiceRequest($container));
    }

    /**
     * Get logs for a single service.
     */
    public function serviceLogs(Environment $environment, string $service, int $lines = 200): array
    {
        $container = $this->getContainerName($service);

        if ($environment->is_local) {
            $result = \Illuminate\Support\Facades\Process::timeout(30)
                ->run("docker logs --tail {$lines} {$container} 2>&1");

            return [
                'success' => true,
                'logs' => $result->output(),
            ];
        }

        return $this->sendRequest($environment, new GetServiceLogsRequest($container, $lines));
    }

    /**
     * Execute a Docker action on a container.
     */
    protected function dockerServiceAction(Environment $environment, string $container, string $action): array
    {
        if ($environment->is_local) {
            $result = \Illuminate\Support\Facades\Process::timeout(60)
                ->run("docker {$action} {$container}");

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'error' => $result->errorOutput() ?: "Failed to {$action} {$container}",
                ];
            }

            return ['success' => true];
        }

        $result = $this->ssh->execute($environment, "sg docker -c 'docker {$action} {$container}'");

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? "Failed to {$action} {$container}",
            ];
        }

        return ['success' => true];
    }

    /**
     * Convert service key to Docker container name.
     */
    protected function getContainerName(string $service): string
    {
        // Map service keys to container names
        $containerMap = [
            'dns' => 'launchpad-dns',
            'caddy' => 'launchpad-caddy',
            'php-83' => 'launchpad-php-83',
            'php-84' => 'launchpad-php-84',
            'php-85' => 'launchpad-php-85',
            'postgres' => 'launchpad-postgres',
            'redis' => 'launchpad-redis',
            'mailpit' => 'launchpad-mailpit',
            'reverb' => 'launchpad-reverb',
        ];

        return $containerMap[$service] ?? 'launchpad-'.$service;
    }

    public function php(Environment $environment, string $site, ?string $version = null): array
    {
        if ($environment->is_local) {
            $command = $version
                ? "php {$site} {$version} --json"
                : "php {$site} --json";

            return $this->executeCommand($environment, $command);
        }

        if ($version) {
            return $this->sendRequest($environment, new SetPhpRequest($site, $version));
        }

        return $this->sendRequest($environment, new GetPhpRequest($site));
    }

    /**
     * Rebuild the DNS container with the correct TLD and HOST_IP.
     * This is needed when TLD changes on a remote server.
     * Also restarts launchpad to regenerate Caddy config with new domains.
     */
    public function rebuildDns(Environment $environment, string $tld): array
    {
        // For local servers, just restart launchpad (handles DNS automatically)
        if ($environment->is_local) {
            return $this->restartWithoutJson($environment);
        }

        // For remote servers, rebuild the DNS container with correct TLD and HOST_IP
        $hostIp = $environment->host;
        $escapedTld = escapeshellarg($tld);
        $escapedHostIp = escapeshellarg($hostIp);

        // Stop and remove existing DNS container
        $this->ssh->execute($environment, 'sg docker -c "docker stop launchpad-dns 2>/dev/null || true"');
        $this->ssh->execute($environment, 'sg docker -c "docker rm launchpad-dns 2>/dev/null || true"');

        // Rebuild DNS image with correct TLD and HOST_IP
        $buildCommand = "sg docker -c 'cd ~/.config/launchpad/dns && TLD={$escapedTld} HOST_IP={$escapedHostIp} docker compose build --no-cache'";
        $buildResult = $this->ssh->execute($environment, $buildCommand, 120); // 2 min timeout for build

        if (! $buildResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to rebuild DNS container: '.($buildResult['error'] ?? 'Unknown error'),
            ];
        }

        // Restart launchpad to regenerate all configs (Caddy, etc.) with new TLD
        // This also starts the DNS container with the rebuilt image
        // Use restartWithoutJson to avoid JSON parsing errors from launchpad output
        $restartResult = $this->restartWithoutJson($environment);

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
    protected function restartWithoutJson(Environment $environment): array
    {
        if ($environment->is_local) {
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
        $path = $this->findBinary($environment);
        if (! $path) {
            return ['success' => false, 'error' => 'Launchpad CLI not found on remote server'];
        }

        $result = $this->ssh->execute($environment, "{$path} restart", 120);

        return [
            'success' => $result['success'],
            'error' => $result['success'] ? null : ($result['error'] ?? 'Command failed'),
        ];
    }

    public function phpReset(Environment $environment, string $site): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, "php {$site} --reset --json");
        }

        return $this->sendRequest($environment, new ResetPhpRequest($site));
    }

    /**
     * Rebuild a project (re-run deps install, build, migrations without git pull).
     */
    public function rebuild(Environment $environment, string $site): array
    {
        if ($environment->is_local) {
            $escapedSite = escapeshellarg($site);

            return $this->executeCommand($environment, "project:update --site={$escapedSite} --no-git --json");
        }

        return $this->sendRequest($environment, new RebuildProjectRequest($site));
    }

    /**
     * Get all worktrees for a server (optionally filtered by site).
     */
    public function worktrees(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site
                ? "worktrees {$site} --json"
                : 'worktrees --json';

            return $this->executeCommand($environment, $command);
        }

        return $this->sendRequest($environment, new GetWorktreesRequest($site));
    }

    /**
     * Unlink a worktree from a site.
     */
    public function unlinkWorktree(Environment $environment, string $site, string $worktreeName): array
    {
        if ($environment->is_local) {
            $escapedSite = escapeshellarg($site);
            $escapedWorktree = escapeshellarg($worktreeName);

            return $this->executeCommand($environment, "worktree:unlink {$escapedSite} {$escapedWorktree} --json");
        }

        return $this->sendRequest($environment, new UnlinkWorktreeRequest($site, $worktreeName));
    }

    /**
     * Refresh worktree detection (re-scan and auto-link new worktrees).
     */
    public function refreshWorktrees(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'worktree:refresh --json');
        }

        return $this->sendRequest($environment, new RefreshWorktreesRequest);
    }

    public function checkInstallation(Environment $environment): array
    {
        // For local servers, check the bundled CLI
        if ($environment->is_local) {
            return $this->checkLocalInstallation();
        }

        // For remote servers, use cached value if fresh
        if ($environment->hasCliCache()) {
            return [
                'installed' => true,
                'path' => $environment->cli_path,
                'version' => $environment->cli_version,
            ];
        }

        // Fetch from API and cache
        return $this->checkRemoteInstallationViaApi($environment);
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

    /**
     * Check remote installation via HTTP API (fast, uses status endpoint).
     * Falls back to SSH if API fails.
     */
    protected function checkRemoteInstallationViaApi(Environment $environment): array
    {
        // Try to get CLI info from status endpoint (includes cli_version and cli_path)
        $status = $this->status($environment);

        if ($status['success'] && isset($status['data']['cli_version'])) {
            $version = $status['data']['cli_version'];
            $path = $status['data']['cli_path'] ?? null;

            // Cache the result
            $environment->updateCliCache($version, $path);

            return [
                'installed' => true,
                'path' => $path,
                'version' => $version,
            ];
        }

        // Fallback to SSH-based check if API doesn't have CLI info
        return $this->checkRemoteInstallationViaSsh($environment);
    }

    /**
     * Check remote installation via SSH (slow, used as fallback).
     */
    protected function checkRemoteInstallationViaSsh(Environment $environment): array
    {
        $path = $this->findBinary($environment);

        if (! $path) {
            return [
                'installed' => false,
                'path' => null,
                'version' => null,
            ];
        }

        $versionResult = $this->ssh->execute($environment, "{$path} --version");
        $version = trim((string) $versionResult['output']);

        // Cache the result
        $environment->updateCliCache($version, $path);

        return [
            'installed' => true,
            'path' => $path,
            'version' => $version,
        ];
    }

    protected function executeCommand(Environment $environment, string $command): array
    {
        // For local servers, use the bundled CLI directly
        if ($environment->is_local) {
            return $this->executeLocalCommand($command);
        }

        // For remote servers, use SSH
        return $this->executeRemoteCommand($environment, $command);
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

    protected function executeRemoteCommand(Environment $environment, string $command): array
    {
        // Use configured path or default to installed phar
        $cliPath = $environment->cli_path ?? '$HOME/.local/bin/launchpad';
        $fullCommand = "{$cliPath} {$command}";

        $result = $this->ssh->executeJson($environment, $fullCommand);

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

    public function getConfig(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->getLocalConfig();
        }

        return $this->getRemoteConfig($environment);
    }

    /**
     * Get Reverb WebSocket configuration for real-time updates.
     * Uses status endpoint to check if Reverb service is running.
     */
    public function getReverbConfig(Environment $environment): array
    {
        // Get status (includes services) to check if Reverb is running
        $status = $this->status($environment);

        if (! $status['success']) {
            return [
                'success' => false,
                'error' => $status['error'] ?? 'Failed to get status',
            ];
        }

        $statusData = $status['data'] ?? [];
        $services = $statusData['services'] ?? [];

        // Check if Reverb is running
        $reverbService = $services['reverb'] ?? null;
        $reverbRunning = ($reverbService['status'] ?? '') === 'running';

        if (! $reverbRunning) {
            return [
                'success' => true,
                'enabled' => false,
            ];
        }

        // Get TLD from environment cache or status
        $tld = $environment->tld ?? $statusData['tld'] ?? 'test';

        return [
            'success' => true,
            'enabled' => true,
            'host' => "reverb.{$tld}",
            'port' => 443,
            'scheme' => 'https',
            'app_key' => 'launchpad-key',
        ];
    }

    protected function getLocalConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/launchpad/config.json';

        if (! file_exists($configPath)) {
            $data = $this->getDefaultConfig();
            $data['available_php_versions'] = $this->getLocalAvailablePhpVersions();

            return [
                'success' => true,
                'data' => $data,
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

        $data = array_merge($this->getDefaultConfig(), $config);
        $data['available_php_versions'] = $this->getLocalAvailablePhpVersions();

        return [
            'success' => true,
            'data' => $data,
            'exists' => true,
        ];
    }

    /**
     * Get available PHP versions for local environment.
     */
    protected function getLocalAvailablePhpVersions(): array
    {
        // Query docker for running launchpad-php-* containers
        $output = shell_exec("docker ps --format '{{.Names}}' --filter 'name=launchpad-php-' 2>/dev/null | grep -oE '[0-9]+' | sort -u");

        if (in_array($output, ['', '0', false, null], true)) {
            return ['8.3', '8.4', '8.5'];
        }

        $versions = [];
        $numbers = explode("\n", trim($output));

        foreach ($numbers as $num) {
            $num = trim($num);
            if (strlen($num) === 2) {
                $versions[] = substr($num, 0, 1).'.'.substr($num, 1);
            }
        }

        usort($versions, version_compare(...));

        return $versions === [] ? ['8.3', '8.4', '8.5'] : $versions;
    }

    protected function getRemoteConfig(Environment $environment): array
    {
        // Use HTTP API to get config
        $result = $this->sendRequest($environment, new GetConfigRequest);

        if (! $result['success']) {
            // Fallback to defaults if API fails
            $data = $this->getDefaultConfig();
            $data['available_php_versions'] = $this->getAvailablePhpVersions($environment);

            return [
                'success' => true,
                'data' => $data,
                'exists' => false,
            ];
        }

        // API returns flat response, merge with defaults
        $data = array_merge($this->getDefaultConfig(), $result);
        unset($data['success']); // Remove success flag from data

        $data['available_php_versions'] = $this->getAvailablePhpVersions($environment);

        return [
            'success' => true,
            'data' => $data,
            'exists' => true,
        ];
    }

    /**
     * Get available PHP versions by scanning running containers.
     */
    protected function getAvailablePhpVersions(Environment $environment): array
    {
        // For remote environments, use HTTP API
        if (! $environment->is_local) {
            $result = $this->sendRequest($environment, new GetPhpVersionsRequest);
            if ($result['success'] && isset($result['versions'])) {
                return $result['versions'];
            }

            // Fallback to default versions
            return ['8.3', '8.4', '8.5'];
        }

        // For local environments, query docker for running launchpad-php-* containers
        $result = $this->ssh->execute(
            $environment,
            "docker ps --format '{{.Names}}' --filter 'name=launchpad-php-' 2>/dev/null | grep -oE '[0-9]+' | sort -u"
        );

        if (! $result['success'] || in_array(trim((string) $result['output']), ['', '0'], true)) {
            // Fallback to default versions
            return ['8.3', '8.4', '8.5'];
        }

        $versions = [];
        $numbers = explode("\n", trim((string) $result['output']));

        foreach ($numbers as $num) {
            $num = trim($num);
            if (strlen($num) === 2) {
                // Convert "83" to "8.3", "84" to "8.4", "85" to "8.5"
                $versions[] = substr($num, 0, 1).'.'.substr($num, 1);
            }
        }

        // Sort versions
        usort($versions, version_compare(...));

        return $versions === [] ? ['8.3', '8.4', '8.5'] : $versions;
    }

    public function saveConfig(Environment $environment, array $config): array
    {
        if ($environment->is_local) {
            return $this->saveLocalConfig($config);
        }

        return $this->saveRemoteConfig($environment, $config);
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

    /**
     * Save config to remote environment via SSH.
     *
     * Note: Config write intentionally uses SSH rather than HTTP API.
     * The launchpad-cli API does not expose a config write endpoint for security
     * reasons - allowing arbitrary config changes via HTTP could be exploited.
     * Config reads use the API (fast, no SSH overhead), but writes require SSH
     * authentication which provides an additional security layer.
     */
    protected function saveRemoteConfig(Environment $environment, array $config): array
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $escapedJson = escapeshellarg($json);

        // Ensure directory exists and write file
        $command = "mkdir -p ~/.config/launchpad && echo {$escapedJson} > ~/.config/launchpad/config.json";
        $result = $this->ssh->execute($environment, $command);

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

    /**
     * Set orchestrator URL in CLI config.
     */
    public function setOrchestratorUrl(Environment $environment, string $url): array
    {
        // Get current config
        $currentConfig = $this->getConfig($environment);

        if (! $currentConfig['success']) {
            // Start with default config if none exists
            $config = $this->getDefaultConfig();
        } else {
            $config = $currentConfig['data'];
        }

        // Add/update orchestrator settings
        $config['orchestrator'] = [
            'url' => $url,
        ];

        // Save the updated config
        return $this->saveConfig($environment, $config);
    }

    /**
     * Remove orchestrator URL from CLI config.
     */
    public function removeOrchestratorUrl(Environment $environment): array
    {
        // Get current config
        $currentConfig = $this->getConfig($environment);

        if (! $currentConfig['success']) {
            return ['success' => true]; // Nothing to remove
        }

        $config = $currentConfig['data'];

        // Remove orchestrator settings
        unset($config['orchestrator']);

        // Save the updated config
        return $this->saveConfig($environment, $config);
    }

    /**
     * Detect existing orchestrator installations in scan paths.
     * Looks for a Laravel app in an 'orchestrator' directory.
     */
    public function detectOrchestrator(Environment $environment): array
    {
        $config = $this->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get config'];
        }

        $paths = $config['data']['paths'] ?? [];
        $tld = $config['data']['tld'] ?? 'test';
        $found = [];

        foreach ($paths as $scanPath) {
            // Check for orchestrator directory
            $orchestratorPath = rtrim((string) $scanPath, '/').'/orchestrator';

            if ($environment->is_local) {
                // Local check - look for Laravel app (artisan + public/index.php)
                if (is_dir($orchestratorPath) &&
                    file_exists($orchestratorPath.'/artisan') &&
                    file_exists($orchestratorPath.'/public/index.php')) {
                    $found[] = [
                        'path' => $orchestratorPath,
                        'url' => "https://orchestrator.{$tld}",
                        'configured' => $environment->orchestrator_url !== null,
                    ];
                }
            } else {
                // Remote check via SSH - look for Laravel app
                $checkCmd = "test -d {$orchestratorPath} && test -f {$orchestratorPath}/artisan && test -f {$orchestratorPath}/public/index.php && echo 'found'";
                $result = $this->ssh->execute($environment, $checkCmd);

                if ($result['success'] && str_contains((string) $result['output'], 'found')) {
                    $found[] = [
                        'path' => $orchestratorPath,
                        'url' => "https://orchestrator.{$tld}",
                        'configured' => $environment->orchestrator_url !== null,
                    ];
                }
            }
        }

        return [
            'success' => true,
            'installations' => $found,
            'tld' => $tld,
        ];
    }

    /**
     * Install orchestrator in the first scan path.
     */
    public function installOrchestrator(Environment $environment): array
    {
        $config = $this->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get config'];
        }

        $paths = $config['data']['paths'] ?? [];
        $tld = $config['data']['tld'] ?? 'test';

        if (empty($paths)) {
            return ['success' => false, 'error' => 'No scan paths configured'];
        }

        $installPath = rtrim((string) $paths[0], '/').'/orchestrator';
        $orchestratorUrl = "https://orchestrator.{$tld}";

        if ($environment->is_local) {
            return $this->installOrchestratorLocal($environment, $installPath, $orchestratorUrl, $tld);
        }

        return $this->installOrchestratorRemote($environment, $installPath, $orchestratorUrl, $tld);
    }

    protected function installOrchestratorLocal(Environment $environment, string $installPath, string $orchestratorUrl, string $tld): array
    {
        // Check if already exists
        if (is_dir($installPath)) {
            return ['success' => false, 'error' => 'Orchestrator directory already exists at '.$installPath];
        }

        // Clone the repository
        $cloneResult = Process::timeout(120)->run("git clone https://github.com/hardimpactdev/orchestrator.git {$installPath}");
        if (! $cloneResult->successful()) {
            return ['success' => false, 'error' => 'Failed to clone orchestrator: '.$cloneResult->errorOutput()];
        }

        // Run composer install
        $composerResult = Process::timeout(300)->path($installPath)->run('composer install --no-interaction');
        if (! $composerResult->successful()) {
            return ['success' => false, 'error' => 'Failed to run composer install: '.$composerResult->errorOutput()];
        }

        // Copy .env.example to .env if it doesn't exist
        if (! file_exists($installPath.'/.env') && file_exists($installPath.'/.env.example')) {
            copy($installPath.'/.env.example', $installPath.'/.env');
        }

        // Generate app key
        Process::timeout(30)->path($installPath)->run('php artisan key:generate');

        // Run migrations
        Process::timeout(60)->path($installPath)->run('php artisan migrate --force');

        // Update environment with orchestrator URL
        $environment->update(['orchestrator_url' => $orchestratorUrl]);

        // Update CLI config with orchestrator URL
        $this->setOrchestratorUrl($environment, $orchestratorUrl);

        return [
            'success' => true,
            'path' => $installPath,
            'url' => $orchestratorUrl,
            'message' => 'Orchestrator installed successfully',
        ];
    }

    protected function installOrchestratorRemote(Environment $environment, string $installPath, string $orchestratorUrl, string $tld): array
    {
        // Check if already exists
        $checkResult = $this->ssh->execute($environment, "test -d {$installPath} && echo 'exists'");
        if (str_contains((string) $checkResult['output'], 'exists')) {
            return ['success' => false, 'error' => 'Orchestrator directory already exists at '.$installPath];
        }

        // Clone the repository
        $cloneResult = $this->ssh->execute($environment, "git clone https://github.com/hardimpactdev/orchestrator.git {$installPath}", 120);
        if (! $cloneResult['success']) {
            return ['success' => false, 'error' => 'Failed to clone orchestrator: '.($cloneResult['error'] ?? 'Unknown error')];
        }

        // Run composer install
        $composerResult = $this->ssh->execute($environment, "cd {$installPath} && composer install --no-interaction", 300);
        if (! $composerResult['success']) {
            return ['success' => false, 'error' => 'Failed to run composer install: '.($composerResult['error'] ?? 'Unknown error')];
        }

        // Copy .env.example to .env if it doesn't exist
        $this->ssh->execute($environment, "cd {$installPath} && test -f .env || cp .env.example .env");

        // Generate app key
        $this->ssh->execute($environment, "cd {$installPath} && php artisan key:generate", 30);

        // Run migrations
        $this->ssh->execute($environment, "cd {$installPath} && php artisan migrate --force", 60);

        // Update environment with orchestrator URL
        $environment->update(['orchestrator_url' => $orchestratorUrl]);

        // Update CLI config with orchestrator URL
        $this->setOrchestratorUrl($environment, $orchestratorUrl);

        return [
            'success' => true,
            'path' => $installPath,
            'url' => $orchestratorUrl,
            'message' => 'Orchestrator installed successfully',
        ];
    }

    /**
     * Reconcile/reconfigure orchestrator linking with CLI.
     * This sets up the MCP connection and ensures proper configuration.
     */
    public function reconcileOrchestrator(Environment $environment, string $orchestratorPath): array
    {
        $config = $this->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get config'];
        }

        $tld = $config['data']['tld'] ?? 'test';
        $orchestratorUrl = "https://orchestrator.{$tld}";

        // Update environment with orchestrator URL
        $environment->update(['orchestrator_url' => $orchestratorUrl]);

        // Update CLI config with orchestrator settings including MCP
        $currentConfig = $config['data'];
        $currentConfig['orchestrator'] = [
            'url' => $orchestratorUrl,
            'mcp' => [
                'endpoint' => $orchestratorUrl.'/mcp',
            ],
        ];

        $saveResult = $this->saveConfig($environment, $currentConfig);
        if (! $saveResult['success']) {
            return ['success' => false, 'error' => 'Failed to save config: '.($saveResult['error'] ?? 'Unknown error')];
        }

        return [
            'success' => true,
            'url' => $orchestratorUrl,
            'path' => $orchestratorPath,
            'message' => 'Orchestrator reconciled successfully',
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

    /**
     * Check if a GitHub repository already exists.
     * Used to validate project names BEFORE starting provisioning.
     *
     * @param  string  $repo  Repository in "owner/name" format (e.g., "nckrtl/my-project")
     * @return array{exists: bool, error?: string}
     */
    public function checkGitHubRepoExists(Environment $environment, string $repo): array
    {
        // Use gh repo view to check if repo exists
        $escapedRepo = escapeshellarg($repo);
        $command = "gh repo view {$escapedRepo} --json name 2>/dev/null && echo 'EXISTS' || echo 'NOT_FOUND'";

        $result = $this->ssh->execute($environment, $command, 15);

        if (! $result['success']) {
            return [
                'exists' => false,
                'error' => 'Failed to check repository: '.($result['error'] ?? 'SSH error'),
            ];
        }

        $output = trim((string) $result['output']);

        // If output contains JSON followed by EXISTS, the repo exists
        if (str_contains($output, 'EXISTS')) {
            return ['exists' => true];
        }

        return ['exists' => false];
    }

    /**
     * Get the GitHub username/org for new repos.
     * Gets from config (for remote) or queries gh CLI (for local).
     */
    public function getGitHubUser(Environment $environment): ?string
    {
        // For remote environments, get from config API (no SSH needed)
        if (! $environment->is_local) {
            $config = $this->getConfig($environment);
            if ($config['success'] && ! empty($config['data']['github_username'])) {
                return $config['data']['github_username'];
            }
        }

        // For local environments, query gh CLI directly
        $command = 'gh api user --jq .login 2>/dev/null';
        if ($environment->is_local) {
            $result = Process::timeout(10)->run($command);
            if ($result->successful() && trim($result->output())) {
                return trim($result->output());
            }
        } else {
            // Fallback to SSH if not in config (shouldn't happen normally)
            $result = $this->ssh->execute($environment, $command, 10);
            if ($result['success'] && ! empty($result['output'])) {
                return trim((string) $result['output']);
            }
        }

        return null;
    }

    /**
     * Create a new project via the CLI.
     *
     * @param  array  $options  Array containing: name, template (optional), is_template (optional), directory (optional), visibility (optional), db_driver, session_driver, cache_driver, queue_driver
     */
    public function createProject(Environment $environment, array $options): array
    {
        // Build request payload
        $payload = [
            'name' => $options['name'],
            'visibility' => $options['visibility'] ?? 'private',
        ];

        // Handle template vs clone
        if (! empty($options['template'])) {
            $isTemplate = $options['is_template'] ?? false;
            if ($isTemplate) {
                $payload['template'] = $options['template'];
            } else {
                // Convert repo to clone URL
                $repo = $options['template'];
                if (! str_starts_with((string) $repo, 'git@') && ! str_starts_with((string) $repo, 'https://')) {
                    $payload['clone_url'] = "git@github.com:{$repo}.git";
                } else {
                    $payload['clone_url'] = $repo;
                }

                if (! empty($options['fork'])) {
                    $payload['fork'] = true;
                }
            }
        }

        // Optional fields
        if (! empty($options['directory'])) {
            $payload['path'] = $options['directory'];
        }
        if (! empty($options['db_driver'])) {
            $payload['db_driver'] = $options['db_driver'];
        }
        if (! empty($options['session_driver'])) {
            $payload['session_driver'] = $options['session_driver'];
        }
        if (! empty($options['cache_driver'])) {
            $payload['cache_driver'] = $options['cache_driver'];
        }
        if (! empty($options['queue_driver'])) {
            $payload['queue_driver'] = $options['queue_driver'];
        }
        if (! empty($options['php_version'])) {
            $payload['php_version'] = $options['php_version'];
        }

        $result = $this->sendRequest($environment, new CreateProjectRequest($payload));

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'slug' => $result['slug'] ?? \Illuminate\Support\Str::slug($options['name']),
                    'status' => 'provisioning',
                    'message' => $result['message'] ?? 'Project creation queued',
                ],
            ];
        }

        return $result;
    }

    /**
     * Check the provisioning status of a project.
     */
    public function provisionStatus(Environment $environment, string $slug): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'provision:status '.escapeshellarg($slug).' --json');
        }

        return $this->sendRequest($environment, new GetProvisionStatusRequest($slug));
    }

    /**
     * Get Linear teams from the orchestrator.
     */
    public function getLinearTeams(Environment $environment): array
    {
        $result = $this->callOrchestratorMcp($environment, 'list-linear-teams', []);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to fetch Linear teams'];
        }

        $data = $result['data'];

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown MCP error'];
        }

        // Teams are returned in the meta data from ListLinearTeamsTool
        $teams = $data['result']['_meta']['teams'] ?? $data['result']['meta']['teams'] ?? [];

        return ['success' => true, 'data' => $teams];
    }

    /**
     * Call an MCP tool on the orchestrator.
     * For remote environments, proxies through SSH to avoid DNS issues.
     */
    protected function callOrchestratorMcp(Environment $environment, string $tool, array $arguments): array
    {
        $orchestratorUrl = $environment->orchestrator_url;

        if (! $orchestratorUrl) {
            return [
                'success' => false,
                'error' => 'No orchestrator URL configured for this environment',
            ];
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
            ],
            'id' => uniqid('mcp_', true),
        ];

        if ($environment->is_local) {
            // Local: direct HTTP call
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(120)
                    ->withoutVerifying()
                    ->post(rtrim($orchestratorUrl, '/').'/mcp', $payload);

                if (! $response->successful()) {
                    return ['success' => false, 'error' => 'HTTP '.$response->status()];
                }

                return ['success' => true, 'data' => $response->json()];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Remote: proxy through SSH using curl
        // Use --resolve to bypass system DNS since the launchpad-dns container
        // may not be configured as the system resolver
        $jsonPayload = json_encode($payload);
        $escapedPayload = escapeshellarg($jsonPayload);
        $mpcUrl = rtrim($orchestratorUrl, '/').'/mcp';
        $escapedUrl = escapeshellarg($mpcUrl);

        // Extract host from URL for --resolve flag
        $parsedUrl = parse_url($orchestratorUrl);
        $host = $parsedUrl['host'] ?? '';
        $port = ($parsedUrl['scheme'] ?? 'https') === 'https' ? 443 : 80;

        $curlCommand = "curl -s -k -X POST {$escapedUrl} --resolve '{$host}:{$port}:127.0.0.1' -H 'Content-Type: application/json' -d {$escapedPayload}";
        $result = $this->ssh->execute($environment, $curlCommand, 120);

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'SSH command failed'];
        }

        $responseData = json_decode((string) $result['output'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'Invalid JSON response: '.$result['output']];
        }

        return ['success' => true, 'data' => $responseData];
    }

    /**
     * Call the orchestrator's create-project MCP tool directly.
     * Used when creating integrated projects that need GitHub/Linear/VibeKanban setup.
     */
    public function createIntegratedProject(Environment $environment, array $options): array
    {
        if (! $environment->orchestrator_url) {
            return [
                'success' => false,
                'error' => 'No orchestrator URL configured for this environment',
            ];
        }

        // Get config to determine local_path and GitHub org
        $config = $this->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Failed to get environment config'];
        }

        $paths = $config['data']['paths'] ?? [];
        if (empty($paths)) {
            return ['success' => false, 'error' => 'No scan paths configured'];
        }

        $projectName = $options['name'];
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9-]/', '-', (string) $projectName));
        $localPath = rtrim((string) $paths[0], '/').'/'.$slug;

        // Get GitHub org from config or default to user's GitHub account
        $githubOrg = $config['data']['github_org'] ?? 'nckrtl';
        $githubRepo = $githubOrg.'/'.$slug;

        $arguments = [
            'name' => $projectName,
            'local_path' => $localPath,
            'github_repo' => $githubRepo,
        ];

        if (! empty($options['template'])) {
            $arguments['template_repo'] = $options['template'];
        }

        if (! empty($options['linearTeamId'])) {
            $arguments['linear_team_id'] = $options['linearTeamId'];
        }

        if (! empty($options['visibility'])) {
            $arguments['github_visibility'] = $options['visibility'];
        }

        $mcpResult = $this->callOrchestratorMcp($environment, 'create-project', $arguments);

        if (! $mcpResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create project: '.($mcpResult['error'] ?? 'Unknown error'),
            ];
        }

        $data = $mcpResult['data'];

        if (isset($data['error'])) {
            return [
                'success' => false,
                'error' => $data['error']['message'] ?? 'Unknown MCP error',
            ];
        }

        // Extract meta from MCP response
        $meta = $data['result']['meta'] ?? [];

        // The orchestrator triggers background provisioning via CLI's `launchpad provision`
        // which handles: GitHub repo creation -> clone -> setup -> Caddy reload
        // We just return 'provisioning' status and let the background process complete
        $tld = $config['data']['tld'] ?? 'test';

        return [
            'success' => true,
            'data' => [
                'slug' => $slug,
                'project_slug' => $slug,
                'path' => $localPath,
                'local_path' => $localPath,
                'site_url' => "https://{$slug}.{$tld}",
                'github_url' => "https://github.com/{$githubRepo}",
                'orchestrator_id' => $meta['project_id'] ?? null,
                'status' => 'provisioning',
                'websocket_channel' => $meta['websocket_channel'] ?? "project.{$slug}",
            ],
        ];
    }

    /**
     * Clone a repository to a local path.
     */
    protected function cloneRepository(Environment $environment, string $repositoryUrl, string $localPath): array
    {
        if ($environment->is_local) {
            $result = \Illuminate\Support\Facades\Process::timeout(120)->run("git clone {$repositoryUrl} {$localPath}");

            return [
                'success' => $result->successful(),
                'error' => $result->successful() ? null : $result->errorOutput(),
            ];
        }

        // Remote - translate Docker paths to host paths for cloning
        // Config may have Docker paths like /app/projects but clone runs on host
        $hostPath = $this->dockerPathToHostPath($localPath);

        // Clone via SSH and ensure checkout
        $result = $this->ssh->execute($environment, "git clone {$repositoryUrl} {$hostPath} && cd {$hostPath} && git checkout", 120);

        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Setup a Laravel project (configure env, create database, run composer setup).
     */
    public function setupProject(Environment $environment, string $project): array
    {
        $escapedProject = escapeshellarg($project);

        return $this->executeCommand($environment, "setup {$escapedProject} --json");
    }

    /**
     * Translate Docker container paths to host paths if needed.
     * Now that CLI runs on host with PHP-FPM, this is usually a no-op.
     */
    protected function dockerPathToHostPath(string $path): string
    {
        // Legacy: /app/projects/foo -> ~/projects/foo (if config still has Docker paths)
        if (str_starts_with($path, '/app/projects')) {
            return str_replace('/app/projects', '~/projects', $path);
        }

        return $path;
    }

    /**
     * Scan for existing projects on a server.
     */
    public function scanProjects(Environment $environment, ?string $path = null, int $depth = 2): array
    {
        $command = 'project:scan';

        if ($path) {
            $command .= ' '.escapeshellarg($path);
        }

        $command .= ' --depth='.escapeshellarg((string) $depth);
        $command .= ' --json';

        return $this->executeCommand($environment, $command);
    }

    /**
     * Update a project (git pull + dependencies + migrations).
     */
    public function updateProject(Environment $environment, string $path, array $options = []): array
    {
        $escapedPath = escapeshellarg($path);
        $command = "project:update {$escapedPath}";

        if (! empty($options['no_deps'])) {
            $command .= ' --no-deps';
        }

        if (! empty($options['no_migrate'])) {
            $command .= ' --no-migrate';
        }

        $command .= ' --json';

        return $this->executeCommand($environment, $command);
    }

    /**
     * Delete a project's files from the filesystem.
     * Note: Orchestrator deletion (VK/Linear cleanup) should be done separately via deleteIntegratedProject().
     */
    public function deleteProject(Environment $environment, string $slug, bool $force = false): array
    {
        if (! $environment->is_local) {
            return $this->sendRequest($environment, new DeleteProjectRequest($slug));
        }

        // For local environments, use SSH-based deletion

        // Get the project path from config
        $config = $this->getConfig($environment);
        if (! $config['success']) {
            return ['success' => false, 'error' => 'Could not read launchpad config'];
        }

        $paths = $config['data']['paths'] ?? ['~/projects'];
        $projectPath = null;

        // Find the project directory
        foreach ($paths as $basePath) {
            $checkPath = rtrim((string) $basePath, '/').'/'.$slug;
            $expandedPath = str_starts_with($checkPath, '~/') ? '$HOME'.substr($checkPath, 1) : $checkPath;

            // Check if directory exists
            $checkResult = $this->ssh->execute($environment, "test -d {$expandedPath} && echo 'exists'");
            if ($checkResult['success'] && str_contains($checkResult['output'] ?? '', 'exists')) {
                $projectPath = $expandedPath;
                break;
            }
        }

        if (! $projectPath) {
            // Project directory doesn't exist - that's fine, maybe already deleted
            return ['success' => true, 'data' => ['message' => 'Project directory not found (already deleted?)']];
        }

        // Delete the project directory (sudo needed because FrankenPHP runs as root and creates root-owned cache files)
        $deleteResult = $this->ssh->execute($environment, "sudo rm -rf {$projectPath}");
        if (! $deleteResult['success']) {
            return ['success' => false, 'error' => 'Failed to delete project directory: '.($deleteResult['error'] ?? 'Unknown error')];
        }

        // Regenerate Caddy config to remove the site
        $this->executeCommand($environment, 'sites --json'); // This triggers Caddy regeneration

        return ['success' => true, 'data' => ['message' => "Project '{$slug}' deleted from filesystem", 'path' => $projectPath]];
    }

    /**
     * Upgrade a standalone project to integrated.
     * Calls the orchestrator's create-project MCP tool with existing repository_url.
     */
    public function upgradeToIntegrated(Environment $environment, array $options): array
    {
        $arguments = [
            'name' => $options['name'],
            'repository_url' => $options['repository_url'],
            'local_path' => $options['local_path'],
        ];

        if (! empty($options['linearTeamId'])) {
            $arguments['linear_team_id'] = $options['linearTeamId'];
        }

        if (! empty($options['description'])) {
            $arguments['description'] = $options['description'];
        }

        $result = $this->callOrchestratorMcp($environment, 'create-project', $arguments);

        if (! $result['success']) {
            return ['success' => false, 'error' => 'Failed to upgrade project: '.($result['error'] ?? 'Unknown error')];
        }

        $data = $result['data'];

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown MCP error'];
        }

        // Extract orchestrator_id from meta
        $meta = $data['result']['meta'] ?? [];

        return [
            'success' => true,
            'data' => [
                'orchestrator_id' => $meta['project_id'] ?? null,
                'integrations' => $meta['integrations'] ?? [],
                'status' => $meta['status'] ?? 'ready',
            ],
        ];
    }

    /**
     * Delete a project via the orchestrator's MCP endpoint directly.
     * Used for integrated projects that need cascade deletion to VK/Linear.
     */
    public function deleteIntegratedProject(Environment $environment, string $slug): array
    {
        $result = $this->callOrchestratorMcp($environment, 'delete-project', [
            'slug' => $slug,
            'confirm_slug' => $slug,
        ]);

        if (! $result['success']) {
            return ['success' => false, 'error' => 'Failed to delete project: '.($result['error'] ?? 'Unknown error')];
        }

        $data = $result['data'];

        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Unknown MCP error'];
        }

        return ['success' => true, 'data' => $data['result'] ?? []];
    }

    /**
     * List all workspaces.
     */
    public function workspacesList(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'workspaces --json');
        }

        return $this->sendRequest($environment, new GetWorkspacesRequest);
    }

    /**
     * Create a new workspace.
     */
    public function workspaceCreate(Environment $environment, string $name): array
    {
        if ($environment->is_local) {
            $escapedName = escapeshellarg($name);

            return $this->executeCommand($environment, "workspace:create {$escapedName} --json");
        }

        return $this->sendRequest($environment, new CreateWorkspaceRequest($name));
    }

    /**
     * Delete a workspace.
     */
    public function workspaceDelete(Environment $environment, string $name): array
    {
        if ($environment->is_local) {
            $escapedName = escapeshellarg($name);

            return $this->executeCommand($environment, "workspace:delete {$escapedName} --force --json");
        }

        return $this->sendRequest($environment, new DeleteWorkspaceRequest($name));
    }

    /**
     * Add a project to a workspace.
     */
    public function workspaceAddProject(Environment $environment, string $workspace, string $project): array
    {
        if ($environment->is_local) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->executeCommand($environment, "workspace:add {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->sendRequest($environment, new AddWorkspaceProjectRequest($workspace, $project));
    }

    /**
     * Remove a project from a workspace.
     */
    public function workspaceRemoveProject(Environment $environment, string $workspace, string $project): array
    {
        if ($environment->is_local) {
            $escapedWorkspace = escapeshellarg($workspace);
            $escapedProject = escapeshellarg($project);

            return $this->executeCommand($environment, "workspace:remove {$escapedWorkspace} {$escapedProject} --json");
        }

        return $this->sendRequest($environment, new RemoveWorkspaceProjectRequest($workspace, $project));
    }

    /**
     * Link a package to an app for local development.
     */
    public function packageLink(Environment $environment, string $package, string $app): array
    {
        if ($environment->is_local) {
            $escapedPackage = escapeshellarg($package);
            $escapedApp = escapeshellarg($app);

            return $this->executeCommand($environment, "package:link {$escapedPackage} {$escapedApp} --json");
        }

        return $this->sendRequest($environment, new LinkPackageRequest($app, $package));
    }

    /**
     * Unlink a package from an app.
     */
    public function packageUnlink(Environment $environment, string $package, string $app): array
    {
        if ($environment->is_local) {
            $escapedPackage = escapeshellarg($package);
            $escapedApp = escapeshellarg($app);

            return $this->executeCommand($environment, "package:unlink {$escapedPackage} {$escapedApp} --json");
        }

        return $this->sendRequest($environment, new UnlinkPackageRequest($app, $package));
    }

    /**
     * List all linked packages for an app.
     */
    public function packageLinked(Environment $environment, string $app): array
    {
        if ($environment->is_local) {
            $escapedApp = escapeshellarg($app);

            return $this->executeCommand($environment, "package:linked {$escapedApp} --json");
        }

        return $this->sendRequest($environment, new GetLinkedPackagesRequest($app));
    }
}
