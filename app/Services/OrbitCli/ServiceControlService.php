<?php

namespace App\Services\OrbitCli;

use App\Http\Integrations\Orbit\Requests\ConfigureServiceRequest;
use App\Http\Integrations\Orbit\Requests\DisableServiceRequest;
use App\Http\Integrations\Orbit\Requests\EnableServiceRequest;
use App\Http\Integrations\Orbit\Requests\GetServiceInfoRequest;
use App\Http\Integrations\Orbit\Requests\GetServiceLogsRequest;
use App\Http\Integrations\Orbit\Requests\ListAvailableServicesRequest;
use App\Http\Integrations\Orbit\Requests\ListServicesRequest;
use App\Http\Integrations\Orbit\Requests\RestartServiceRequest;
use App\Http\Integrations\Orbit\Requests\RestartServicesRequest;
use App\Http\Integrations\Orbit\Requests\StartServiceRequest;
use App\Http\Integrations\Orbit\Requests\StartServicesRequest;
use App\Http\Integrations\Orbit\Requests\StopServiceRequest;
use App\Http\Integrations\Orbit\Requests\StopServicesRequest;
use App\Models\Environment;
use App\Services\OrbitCli\Shared\CommandService;
use App\Services\OrbitCli\Shared\ConnectorService;
use App\Services\SshService;
use Illuminate\Support\Facades\Process;

/**
 * Service for controlling orbit Docker services.
 */
class ServiceControlService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh
    ) {}

    /**
     * List all services and their status.
     */
    public function list(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, 'service:list --json');
        }

        return $this->connector->sendRequest($environment, new ListServicesRequest);
    }

    /**
     * List available services that can be enabled.
     */
    public function available(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, 'service:available --json');
        }

        return $this->connector->sendRequest($environment, new ListAvailableServicesRequest);
    }

    /**
     * Enable a service.
     */
    public function enable(Environment $environment, string $service, array $options = []): array
    {
        if ($environment->is_local) {
            $optionsJson = json_encode($options);
            $escapedOptions = escapeshellarg($optionsJson);

            return $this->command->executeCommand($environment, "service:enable {$service} --options={$escapedOptions} --json");
        }

        return $this->connector->sendRequest($environment, new EnableServiceRequest($service, $options));
    }

    /**
     * Disable a service.
     */
    public function disable(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, "service:disable {$service} --json");
        }

        return $this->connector->sendRequest($environment, new DisableServiceRequest($service));
    }

    /**
     * Update service configuration.
     */
    public function configure(Environment $environment, string $service, array $config): array
    {
        if ($environment->is_local) {
            $configJson = json_encode($config);
            $escapedConfig = escapeshellarg($configJson);

            return $this->command->executeCommand($environment, "service:config {$service} --config={$escapedConfig} --json");
        }

        return $this->connector->sendRequest($environment, new ConfigureServiceRequest($service, $config));
    }

    /**
     * Get detailed info for a service.
     */
    public function info(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, "service:info {$service} --json");
        }

        return $this->connector->sendRequest($environment, new GetServiceInfoRequest($service));
    }

    /**
     * Start orbit services.
     */
    public function start(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "start {$site} --json" : 'start --json';

            return $this->command->executeCommand($environment, $command);
        }

        // Note: Site-specific start not supported via API yet
        return $this->connector->sendRequest($environment, new StartServicesRequest);
    }

    /**
     * Stop orbit services.
     */
    public function stop(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "stop {$site} --json" : 'stop --json';

            return $this->command->executeCommand($environment, $command);
        }

        return $this->connector->sendRequest($environment, new StopServicesRequest);
    }

    /**
     * Restart orbit services.
     */
    public function restart(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site ? "restart {$site} --json" : 'restart --json';

            return $this->command->executeCommand($environment, $command);
        }

        return $this->connector->sendRequest($environment, new RestartServicesRequest);
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

        return $this->connector->sendRequest($environment, new StartServiceRequest($container));
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

        return $this->connector->sendRequest($environment, new StopServiceRequest($container));
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

        return $this->connector->sendRequest($environment, new RestartServiceRequest($container));
    }

    /**
     * Start a host service (Caddy, PHP-FPM, Horizon).
     */
    public function startHostService(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->hostServiceAction($environment, $service, 'start');
        }

        return $this->command->executeCommand($environment, "host:start {$service} --json");
    }

    /**
     * Stop a host service (Caddy, PHP-FPM, Horizon).
     */
    public function stopHostService(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->hostServiceAction($environment, $service, 'stop');
        }

        return $this->command->executeCommand($environment, "host:stop {$service} --json");
    }

    /**
     * Restart a host service (Caddy, PHP-FPM, Horizon).
     */
    public function restartHostService(Environment $environment, string $service): array
    {
        if ($environment->is_local) {
            return $this->hostServiceAction($environment, $service, 'restart');
        }

        return $this->command->executeCommand($environment, "host:restart {$service} --json");
    }

    /**
     * Get logs for a single service.
     */
    public function serviceLogs(Environment $environment, string $service, int $lines = 200): array
    {
        $container = $this->getContainerName($service);

        if ($environment->is_local) {
            $result = Process::timeout(30)
                ->run("docker logs --tail {$lines} {$container} 2>&1");

            return [
                'success' => true,
                'logs' => $result->output(),
            ];
        }

        return $this->connector->sendRequest($environment, new GetServiceLogsRequest($container, $lines));
    }

    /**
     * Get logs for a host service (Caddy, PHP-FPM, Horizon).
     */
    public function hostServiceLogs(Environment $environment, string $service, int $lines = 200): array
    {
        if ($environment->is_local) {
            return $this->getLocalHostServiceLogs($service, $lines);
        }

        // For remote environments, delegate to CLI
        return $this->command->executeCommand($environment, "host:logs {$service} --lines={$lines} --json");
    }

    /**
     * Get logs for a local host service.
     */
    protected function getLocalHostServiceLogs(string $service, int $lines): array
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            // macOS: use Homebrew log locations or journalctl equivalent
            if ($service === 'caddy') {
                // Caddy logs via brew services
                $logPath = '/opt/homebrew/var/log/caddy.log';
                if (! file_exists($logPath)) {
                    $logPath = '/usr/local/var/log/caddy.log';
                }
            } elseif (str_starts_with($service, 'php')) {
                // PHP-FPM logs - Homebrew uses a single shared log file
                $logPath = '/opt/homebrew/var/log/php-fpm.log';
                if (! file_exists($logPath)) {
                    $logPath = '/usr/local/var/log/php-fpm.log';
                }
            } elseif ($service === 'horizon') {
                // Horizon logs from Laravel app
                $logPath = storage_path('logs/laravel.log');
            } else {
                return [
                    'success' => false,
                    'error' => "Unknown host service: {$service}",
                ];
            }

            if (file_exists($logPath)) {
                $result = Process::timeout(10)->run("tail -n {$lines} ".escapeshellarg($logPath));

                return [
                    'success' => true,
                    'logs' => $result->output() ?: 'No logs available',
                ];
            }

            return [
                'success' => true,
                'logs' => "Log file not found: {$logPath}",
            ];
        } else {
            // Linux: use journalctl
            $unit = $service;
            if (str_starts_with($service, 'php')) {
                $version = str_replace('php-', '', $service);
                $unit = "php{$version}-fpm";
            }

            $result = Process::timeout(10)->run("journalctl -u {$unit} -n {$lines} --no-pager 2>&1");

            return [
                'success' => true,
                'logs' => $result->output() ?: 'No logs available',
            ];
        }
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
        return $this->command->executeRawCommand($environment, 'restart');
    }

    /**
     * Execute a host service action.
     */
    protected function hostServiceAction(Environment $environment, string $service, string $action): array
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            if ($service === 'horizon') {
                $result = Process::run("launchctl {$action} com.laravel.horizon");
            } else {
                // For PHP-FPM and Caddy on Mac (Homebrew)
                $brewService = $service;
                if (str_starts_with($service, 'php')) {
                    // php-8.3 -> php@8.3
                    $brewService = str_replace('php-', 'php@', $service);
                }
                $result = Process::run("brew services {$action} {$brewService}");
            }
        } else {
            // Linux: systemctl
            $unit = $service;
            if (str_starts_with($service, 'php')) {
                // php-8.3 -> php8.3-fpm
                $version = str_replace('php-', '', $service);
                $unit = "php{$version}-fpm";
            }
            $result = Process::run("sudo systemctl {$action} {$unit}");
        }

        if (! $result->successful()) {
            return [
                'success' => false,
                'error' => $result->errorOutput() ?: "Failed to {$action} {$service}",
            ];
        }

        return ['success' => true];
    }

    /**
     * Execute a Docker action on a container.
     */
    protected function dockerServiceAction(Environment $environment, string $container, string $action): array
    {
        if ($environment->is_local) {
            $result = Process::timeout(60)
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
}
