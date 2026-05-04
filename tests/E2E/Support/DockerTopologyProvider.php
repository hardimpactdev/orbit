<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class DockerTopologyProvider implements E2ETopologyProvider
{
    public function __construct(
        private E2EConfig $config,
    ) {}

    public function name(): string
    {
        return 'docker';
    }

    public function capabilities(): E2ETopologyCapabilities
    {
        return E2ETopologyCapabilities::containerFeature();
    }

    public function availability(E2ETopologyKind $kind): ProviderAvailability
    {
        $selection = $this->selectHost($kind);

        if ($selection['host'] !== null) {
            return ProviderAvailability::available("docker prepared topology {$kind->value} is available on {$selection['host']->host}");
        }

        return ProviderAvailability::unavailable(implode('; ', $selection['failures']));
    }

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
    {
        $selection = $this->selectHost($kind);
        $host = $selection['host'];

        if ($host === null) {
            throw new \RuntimeException(implode('; ', $selection['failures']));
        }

        $network = "{$this->config->instancePrefix}-{$runId}";
        $roles = $this->rolesFor($kind);
        $instances = [];

        try {
            $timer->measure('docker.network', fn () => $this->createNetwork($host, $network));

            foreach ($roles as $role) {
                $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
                $image = $this->imageNameFor($kind, $role);
                $ip = $this->ipForRole($role);

                $timer->measure("docker.start.{$role}", fn () => $this->startContainer($host, $name, $network, $ip, $image));

                $instances[$role] = new DockerInstance($host, $name, $network);
            }

            if (isset($instances['gateway'])) {
                $timer->measure('docker.gateway-restart', fn () => E2EGatewayApi::start($instances['gateway'], "topology-{$runId}"));
            }
        } catch (\Throwable $exception) {
            $this->cleanupResources($host, $network, $roles, $runId);

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $network, $roles): array {
            foreach ($roles as $role) {
                $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
                $cycleTimer->measure("reset.delete.{$role}", fn () => $host->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg($name)), timeoutSeconds: 60));
            }

            $cycleTimer->measure('reset.network.recreate', function () use ($host, $network): void {
                $host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 30);
                $this->createNetwork($host, $network);
            });

            $newInstances = [];

            foreach ($roles as $role) {
                $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
                $image = $this->imageNameFor($kind, $role);
                $ip = $this->ipForRole($role);

                $cycleTimer->measure("reset.start.{$role}", fn () => $this->startContainer($host, $name, $network, $ip, $image));

                $newInstances[$role] = new DockerInstance($host, $name, $network);
            }

            if (isset($newInstances['gateway'])) {
                $cycleTimer->measure('reset.gateway-restart', fn () => E2EGatewayApi::start($newInstances['gateway'], "topology-{$runId}"));
            }

            return [
                'instances' => $newInstances,
                'snapshotReset' => null,
            ];
        };

        $teardown = fn (E2EPhaseTimer $cleanupTimer) => $cleanupTimer->measure(
            'cleanup.network',
            fn () => $host->run(
                sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)),
                timeoutSeconds: 30,
            ),
        );

        return new E2ETopologyLease(
            kind: $kind,
            control: $instances['control'],
            gateway: $instances['gateway'] ?? null,
            dev: $instances['dev'] ?? null,
            prod: $instances['prod'] ?? null,
            sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
            rebuild: $rebuild,
            snapshotReset: null,
            teardown: $teardown,
        );
    }

    public function imageNameFor(E2ETopologyKind $kind, string $role): string
    {
        return "orbit-e2e-topology:{$kind->value}-{$role}-current";
    }

    /**
     * @return list<string>
     */
    public function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Control => ['control'],
            E2ETopologyKind::ControlGateway => ['control', 'gateway'],
            E2ETopologyKind::ControlGatewayDev => ['control', 'gateway', 'dev'],
            E2ETopologyKind::ControlGatewayDevProd => ['control', 'gateway', 'dev', 'prod'],
        };
    }

    /**
     * @return array{host: DockerHost|null, failures: list<string>}
     */
    private function selectHost(E2ETopologyKind $kind): array
    {
        $failures = [];
        $requestedContainers = count($this->rolesFor($kind));

        foreach ($this->config->dockerHosts as $hostName) {
            $host = new DockerHost($this->config, $hostName);

            if (! $host->run('command -v docker >/dev/null', timeoutSeconds: 10)->successful()) {
                $failures[] = "{$hostName}: docker command is not available";

                continue;
            }

            if (! $host->run('docker info >/dev/null', timeoutSeconds: 10)->successful()) {
                $failures[] = "{$hostName}: docker daemon is not reachable";

                continue;
            }

            $missingImage = $this->missingImage($host, $kind);

            if ($missingImage !== null) {
                $failures[] = "{$hostName}: docker prepared image {$missingImage} is not available";

                continue;
            }

            $runningContainers = $this->runningE2EContainerCount($host);

            if ($runningContainers + $requestedContainers > $this->config->dockerMaxContainersPerHost) {
                $failures[] = "{$hostName}: docker capacity exceeded";

                continue;
            }

            return ['host' => $host, 'failures' => $failures];
        }

        return ['host' => null, 'failures' => $failures];
    }

    private function missingImage(DockerHost $host, E2ETopologyKind $kind): ?string
    {
        foreach ($this->rolesFor($kind) as $role) {
            $image = $this->imageNameFor($kind, $role);

            if (! $host->run(sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)), timeoutSeconds: 10)->successful()) {
                return $image;
            }
        }

        return null;
    }

    private function runningE2EContainerCount(DockerHost $host): int
    {
        $result = $host->run(sprintf(
            'docker ps --format %s --filter %s',
            escapeshellarg('{{.Names}}'),
            escapeshellarg("name={$this->config->instancePrefix}-"),
        ), timeoutSeconds: 10);

        if (! $result->successful()) {
            return $this->config->dockerMaxContainersPerHost + 1;
        }

        return count(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn (string $name): bool => str_starts_with($name, "{$this->config->instancePrefix}-"),
        ));
    }

    private function createNetwork(DockerHost $host, string $network): void
    {
        $host->mustRun(
            sprintf('docker network create --subnet %s %s', escapeshellarg('10.6.0.0/16'), escapeshellarg($network)),
            "Could not create Docker network {$network}",
        );
    }

    private function startContainer(DockerHost $host, string $name, string $network, string $ip, string $image): void
    {
        $host->mustRun(
            sprintf(
                'docker run -d --name %s --network %s --ip %s --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s',
                escapeshellarg($name),
                escapeshellarg($network),
                escapeshellarg($ip),
                escapeshellarg($image),
            ),
            "Could not start container {$name}",
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function cleanupResources(DockerHost $host, string $network, array $roles, string $runId): void
    {
        foreach ($roles as $role) {
            $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
            $host->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg($name)), timeoutSeconds: 60);
        }

        $host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 30);
    }

    private function ipForRole(string $role): string
    {
        return match ($role) {
            'gateway' => '10.6.0.2',
            'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            default => throw new \RuntimeException("Unknown Docker topology role {$role}."),
        };
    }
}
