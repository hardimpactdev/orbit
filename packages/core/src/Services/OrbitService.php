<?php

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\OrbitConnector;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\AddWorkspaceProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ConfigureServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateWorkspaceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteWorkspaceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DisableServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\EnableServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetConfigRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetLinkedPackagesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetPhpVersionsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetProjectsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetProvisionStatusRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetServiceInfoRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetServiceLogsRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetStatusRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetWorkspacesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\GetWorktreesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\LinkPackageRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ListAvailableServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ListServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RebuildProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RefreshWorktreesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RemoveWorkspaceProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ResetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RestartServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\RestartServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\SetPhpRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StartServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StartServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StopServiceRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\StopServicesRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\UnlinkPackageRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\UnlinkWorktreeRequest;
use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Request;

class OrbitService
{
    // Common installation paths for orbit on remote servers
    // Installed phar first, then dev paths as fallback
    protected array $binaryPaths = [
        '$HOME/.local/bin/orbit',
        '/usr/local/bin/orbit',
        '$HOME/projects/orbit-cli/orbit',
        '$HOME/projects/orbit/orbit',
        '$HOME/.composer/vendor/bin/orbit',
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
        $result = $this->ssh->execute($environment, 'cat ~/.config/orbit/config.json 2>/dev/null');
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
     * Get the Saloon connector for the orbit web app API.
     */
    protected function getConnector(Environment $environment, int $timeout = 30): OrbitConnector
    {
        $tld = $this->getTld($environment);

        return OrbitConnector::forEnvironment($tld, $timeout);
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
        // Single SSH call to find orbit binary
        // Check paths in order of preference (installed phar first)
        $command = <<<'BASH'
for path in "$HOME/.local/bin/orbit" "/usr/local/bin/orbit" "$HOME/projects/orbit-cli/orbit" "$HOME/projects/orbit/orbit" "$HOME/.composer/vendor/bin/orbit"; do
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

        return $this->sendRequest($environment, new GetProjectsRequest);
    }

    /**
     * Get all sites from CLI (fresh, no caching).
     * Returns all directories in scan paths, with has_public_folder flag.
     */
    public function siteList(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'site:list --json');
        }

        return $this->sendRequest($environment, new GetProjectsRequest);
    }

    /**
     * List all services and their status.
     */
    public function listServices(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'service:list --json');
        }

        return $this->sendRequest($environment, new ListServicesRequest);
    }

    /**
     * List available services that can be enabled.
     */
    public function availableServices(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, 'service:available --json');
        }

        return $this->sendRequest($environment, new ListAvailableServicesRequest);
    }

    /**
     * Enable a service.
     */
    public function enableService(Environment $environment, string $service, array $options = []): array
    {
        if ($environment->is_local) {
            $optionsJson = json_encode($options);
            $escapedOptions = escapeshellarg($optionsJson);

            return $this->executeCommand($environment, "service:enable {$service} --options={$escapedOptions} --json");
        }

        return $this->sendRequest($environment, new EnableServiceRequest($service, $options));
    }

    /**
     * Disable a service.
     */
    public function disableService(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, "service:disable {$service} --json");
        }

        return $this->sendRequest($environment, new DisableServiceRequest($service));
    }

    /**
     * Update service configuration.
     */
    public function configureService(Environment $environment, string $service, array $config): array
    {
        if ($environment->is_local) {
            $configJson = json_encode($config);
            $escapedConfig = escapeshellarg($configJson);

            return $this->executeCommand($environment, "service:config {$service} --config={$escapedConfig} --json");
        }

        return $this->sendRequest($environment, new ConfigureServiceRequest($service, $config));
    }

    /**
     * Get detailed info for a service.
     */
    public function getServiceInfo(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->executeCommand($environment, "service:info {$service} --json");
        }

        return $this->sendRequest($environment, new GetServiceInfoRequest($service));
    }

    public function start(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "start {$site} --json" : 'start --json';

            return $this->executeCommand($environment, $command);
        }

        // Note: Project-specific start not supported via API yet
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
        // Note: Caddy runs on the host via systemd, not in Docker
        $containerMap = [
            'dns' => 'orbit-dns',
            'php-83' => 'orbit-php-83',
            'php-84' => 'orbit-php-84',
            'php-85' => 'orbit-php-85',
            'postgres' => 'orbit-postgres',
            'redis' => 'orbit-redis',
            'mailpit' => 'orbit-mailpit',
            'reverb' => 'orbit-reverb',
        ];

        return $containerMap[$service] ?? 'orbit-'.$service;
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
     * Also restarts orbit to regenerate Caddy config with new domains.
     */
    public function rebuildDns(Environment $environment, string $tld): array
    {
        // For local servers, just restart orbit (handles DNS automatically)
        if ($environment->is_local) {
            return $this->restartWithoutJson($environment);
        }

        // For remote servers, rebuild the DNS container with correct TLD and HOST_IP
        $hostIp = $environment->host;
        $escapedTld = escapeshellarg($tld);
        $escapedHostIp = escapeshellarg($hostIp);

        // Stop and remove existing DNS container
        $this->ssh->execute($environment, 'sg docker -c "docker stop orbit-dns 2>/dev/null || true"');
        $this->ssh->execute($environment, 'sg docker -c "docker rm orbit-dns 2>/dev/null || true"');

        // Rebuild DNS image with correct TLD and HOST_IP
        $buildCommand = "sg docker -c 'cd ~/.config/orbit/dns && TLD={$escapedTld} HOST_IP={$escapedHostIp} docker compose build --no-cache'";
        $buildResult = $this->ssh->execute($environment, $buildCommand, 120); // 2 min timeout for build

        if (! $buildResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to rebuild DNS container: '.($buildResult['error'] ?? 'Unknown error'),
            ];
        }

        // Restart orbit to regenerate all configs (Caddy, etc.) with new TLD
        // This also starts the DNS container with the rebuilt image
        // Use restartWithoutJson to avoid JSON parsing errors from orbit output
        $restartResult = $this->restartWithoutJson($environment);

        if (! $restartResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to restart orbit after DNS rebuild: '.($restartResult['error'] ?? 'Unknown error'),
            ];
        }

        return [
            'success' => true,
            'message' => "DNS rebuilt and orbit restarted with TLD={$tld}",
        ];
    }

    /**
     * Restart orbit without expecting JSON output.
     * Used by rebuildDns where we just need success/failure, not parsed data.
     */
    protected function restartWithoutJson(Environment $environment): array
    {
        if ($environment->is_local) {
            if (! $this->cliUpdate->isInstalled()) {
                return ['success' => false, 'error' => 'Orbit CLI not installed.'];
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
            return ['success' => false, 'error' => 'Orbit CLI not found on remote server'];
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

            return $this->executeCommand($environment, "site:update --site={$escapedSite} --no-git --json");
        }

        return $this->sendRequest($environment, new RebuildProjectRequest($site));
    }

    /**
     * Get all worktrees for a server (optionally filtered by project).
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
                'error' => 'Orbit CLI not installed.',
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
        $cliPath = $environment->cli_path ?? '$HOME/.local/bin/orbit';
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
            'app_key' => 'orbit-key',
        ];
    }

    protected function getLocalConfig(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/orbit/config.json';

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
        // Query docker for running orbit-php-* containers
        $output = shell_exec("docker ps --format '{{.Names}}' --filter 'name=orbit-php-' 2>/dev/null | grep -oE '[0-9]+' | sort -u");

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

        // For local environments, query docker for running orbit-php-* containers
        $result = $this->ssh->execute(
            $environment,
            "docker ps --format '{{.Names}}' --filter 'name=orbit-php-' 2>/dev/null | grep -oE '[0-9]+' | sort -u"
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
        $configDir = $home.'/.config/orbit';
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
     * The orbit-cli API does not expose a config write endpoint for security
     * reasons - allowing arbitrary config changes via HTTP could be exploited.
     * Config reads use the API (fast, no SSH overhead), but writes require SSH
     * authentication which provides an additional security layer.
     */
    protected function saveRemoteConfig(Environment $environment, array $config): array
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $escapedJson = escapeshellarg($json);

        // Ensure directory exists and write file
        $command = "mkdir -p ~/.config/orbit && echo {$escapedJson} > ~/.config/orbit/config.json";
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
        $command = 'site:scan';

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
            return ['success' => false, 'error' => 'Could not read orbit config'];
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
