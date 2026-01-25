<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class DockerManager
{
    protected string $basePath;

    protected ?string $lastError = null;

    /**
     * Cached container statuses from batch query.
     *
     * @var array<string, array{running: bool, health: ?string, container: string}>|null
     */
    protected ?array $statusCache = null;

    public function __construct(protected ConfigManager $configManager)
    {
        $this->basePath = $this->configManager->getConfigPath();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get status and health for all orbit containers in a single batch query.
     * This is much faster than calling isRunning() and getHealthStatus() individually.
     *
     * @return array<string, array{running: bool, health: ?string, container: string}>
     */
    public function getAllStatuses(): array
    {
        if ($this->statusCache !== null) {
            return $this->statusCache;
        }

        $statuses = [];

        // Single query to get all running orbit containers with their health status
        $result = Process::run(
            "docker ps --filter 'name=orbit-' --format '{{.Names}}|{{if .Status}}{{.Status}}{{end}}' 2>/dev/null"
        );

        if (! $result->successful()) {
            return $statuses;
        }

        $runningContainers = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            if (empty($line)) {
                continue;
            }
            [$containerName] = explode('|', $line, 2);
            $runningContainers[] = $containerName;

            // Extract service name from container name (orbit-redis -> redis)
            $serviceName = str_replace('orbit-', '', $containerName);
            $statuses[$serviceName] = [
                'running' => true,
                'health' => null,
                'container' => $containerName,
            ];
        }

        // Batch health check for all running containers
        if (! empty($runningContainers)) {
            $containerList = implode(' ', $runningContainers);
            $healthResult = Process::run(
                "docker inspect --format '{{.Name}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' {$containerList} 2>/dev/null"
            );

            if ($healthResult->successful()) {
                foreach (explode("\n", trim($healthResult->output())) as $line) {
                    if (empty($line)) {
                        continue;
                    }
                    [$containerName, $health] = explode('|', $line, 2);
                    $containerName = ltrim($containerName, '/'); // docker inspect returns /container_name

                    // Extract service name
                    $serviceName = str_replace('orbit-', '', $containerName);

                    if (isset($statuses[$serviceName])) {
                        $statuses[$serviceName]['health'] = $health === 'none' ? null : $health;
                    }
                }
            }
        }

        $this->statusCache = $statuses;

        return $statuses;
    }

    /**
     * Clear the status cache (call after starting/stopping containers).
     */
    public function clearStatusCache(): void
    {
        $this->statusCache = null;
    }

    public function startAll(): void
    {
        $this->clearStatusCache();
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';

        if (! file_exists($composePath)) {
            $this->lastError = "docker-compose.yaml not found. Run 'orbit init' first.";

            return;
        }

        Process::run("docker compose -f {$composePath} up -d");
    }

    public function stopAll(): void
    {
        $this->clearStatusCache();
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';

        if (! file_exists($composePath)) {
            return;
        }

        Process::run("docker compose -f {$composePath} down");
    }

    public function start(string $service): bool
    {
        $this->clearStatusCache();
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';

        if (! file_exists($composePath)) {
            $this->lastError = 'docker-compose.yaml not found';

            return false;
        }

        $result = Process::run("docker compose -f {$composePath} up -d {$service}");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function stop(string $service): bool
    {
        $this->clearStatusCache();
        $composePath = $this->configManager->getConfigPath().'/docker-compose.yaml';

        if (! file_exists($composePath)) {
            $this->lastError = 'docker-compose.yaml not found';

            return false;
        }

        $result = Process::run("docker compose -f {$composePath} stop {$service}");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function restart(string $service): bool
    {
        $this->stop($service);

        return $this->start($service);
    }

    public function build(string $service): bool
    {
        // For services that need building (like dns), use legacy compose files
        $legacyComposePath = "{$this->basePath}/{$service}/docker-compose.yml";

        if (! file_exists($legacyComposePath)) {
            $this->lastError = "Build config not found for {$service}";

            return false;
        }

        $env = $this->getServiceEnv($service);
        $result = Process::env($env)->run("docker compose -f {$legacyComposePath} build");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function pull(string $service): bool
    {
        // For pulling images, use legacy compose files
        $legacyComposePath = "{$this->basePath}/{$service}/docker-compose.yml";

        if (! file_exists($legacyComposePath)) {
            $this->lastError = "Compose file not found for {$service}";

            return false;
        }

        $result = Process::run("docker compose -f {$legacyComposePath} pull");

        if (! $result->successful()) {
            $this->lastError = $result->errorOutput() ?: $result->output();
        }

        return $result->successful();
    }

    public function logs(string $container, bool $follow = true): void
    {
        $followFlag = $follow ? '-f' : '';
        Process::forever()->tty()->run("docker logs {$followFlag} {$container}");
    }

    public function createNetwork(): bool
    {
        // Check if network exists
        $result = Process::run('docker network inspect orbit 2>/dev/null');

        if ($result->successful()) {
            return true; // Network already exists
        }

        // Create network
        $result = Process::run('docker network create orbit');

        return $result->successful();
    }

    /**
     * Check if a container is running.
     * Prefer using getAllStatuses() for batch queries.
     */
    public function isRunning(string $container): bool
    {
        // Use cache if available
        if ($this->statusCache !== null) {
            $serviceName = str_replace('orbit-', '', $container);
            if (isset($this->statusCache[$serviceName])) {
                return $this->statusCache[$serviceName]['running'];
            }
        }

        $result = Process::run("docker ps -q -f name={$container}");

        return ! empty(trim($result->output()));
    }

    /**
     * Get the health status of a container.
     * Prefer using getAllStatuses() for batch queries.
     *
     * @return string|null 'healthy', 'unhealthy', 'starting', or null if no healthcheck
     */
    public function getHealthStatus(string $container): ?string
    {
        // Use cache if available
        if ($this->statusCache !== null) {
            $serviceName = str_replace('orbit-', '', $container);
            if (isset($this->statusCache[$serviceName])) {
                return $this->statusCache[$serviceName]['health'];
            }
        }

        $result = Process::run(
            "docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' {$container} 2>/dev/null"
        );

        if (! $result->successful()) {
            return null;
        }

        $status = trim($result->output());

        return $status === 'none' ? null : $status;
    }

    protected function getServiceEnv(string $service): array
    {
        if ($service === 'dns') {
            return [
                'CONFIG_PATH' => $this->configManager->getConfigPath(),
            ];
        }

        return [];
    }

    /**
     * Check if a container exists (running or stopped).
     */
    public function containerExists(string $name): bool
    {
        $result = Process::run("docker ps -a --format '{{.Names}}' | grep -q '^{$name}$'");

        return $result->successful();
    }

    /**
     * Remove a container (stops it first if running).
     */
    public function removeContainer(string $name): bool
    {
        if (! $this->containerExists($name)) {
            return true;
        }

        // Stop if running
        Process::run("docker stop {$name} 2>/dev/null");

        // Remove container
        $result = Process::run("docker rm {$name}");

        return $result->successful();
    }
}
