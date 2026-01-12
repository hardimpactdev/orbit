<?php

namespace App\Services\LaunchpadCli;

use App\Http\Integrations\Launchpad\Requests\GetServiceLogsRequest;
use App\Http\Integrations\Launchpad\Requests\RestartServiceRequest;
use App\Http\Integrations\Launchpad\Requests\RestartServicesRequest;
use App\Http\Integrations\Launchpad\Requests\StartServiceRequest;
use App\Http\Integrations\Launchpad\Requests\StartServicesRequest;
use App\Http\Integrations\Launchpad\Requests\StopServiceRequest;
use App\Http\Integrations\Launchpad\Requests\StopServicesRequest;
use App\Models\Environment;
use App\Services\LaunchpadCli\Shared\CommandService;
use App\Services\LaunchpadCli\Shared\ConnectorService;
use App\Services\SshService;
use Illuminate\Support\Facades\Process;

/**
 * Service for controlling launchpad Docker services.
 */
class ServiceControlService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command,
        protected SshService $ssh
    ) {}

    /**
     * Start launchpad services.
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
     * Stop launchpad services.
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
     * Restart launchpad services.
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
        return $this->command->executeRawCommand($environment, 'restart');
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
}
