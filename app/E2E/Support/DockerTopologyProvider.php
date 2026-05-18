<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class DockerTopologyProvider implements E2ETopologyProvider
{
    private const int DockerMetadataProbeTimeoutSeconds = 120;

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
        $resourceLease = $this->acquireResourceLease();
        $selection = $this->selectHost($kind, $resourceLease !== null ? [$resourceLease->host()] : null);
        $host = $selection['host'];

        if ($host === null) {
            $resourceLease?->release();

            throw new \RuntimeException(implode('; ', $selection['failures']));
        }

        $network = "{$this->config->instancePrefix}-{$runId}";
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment();
        $roles = $this->rolesFor($kind);
        $instances = [];

        try {
            $timer->measure('docker.network', fn () => $this->createNetwork($host, $network, $networkPlan));

            foreach ($roles as $role) {
                $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
                $image = $this->imageNameFor($kind, $role);
                $ip = $networkPlan->ipForRole($role);

                $timer->measure("docker.start.{$role}", fn () => $this->startContainer($host, $name, $network, $ip, $image));

                $instances[$role] = new DockerInstance($host, $name, $network);
            }

            $timer->measure('docker.retarget', fn () => $this->retargetTopology($instances, $networkPlan));

            if ($options->startGatewayApi && isset($instances['gateway'])) {
                $timer->measure('docker.gateway-restart', fn () => E2EGatewayApi::start(
                    $instances['gateway'],
                    "topology-{$runId}",
                    gatewayIp: $networkPlan->ipForRole('gateway'),
                ));
            }
        } catch (\Throwable $exception) {
            $this->cleanupResources($host, $network, $roles, $runId);
            $resourceLease?->release();

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $network, $roles, $options, $networkPlan): array {
            foreach ($roles as $role) {
                $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
                $cycleTimer->measure("reset.delete.{$role}", fn () => $host->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg($name)), timeoutSeconds: 60));
            }

            $cycleTimer->measure('reset.network.recreate', function () use ($host, $network, $networkPlan): void {
                $host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 30);
                $this->createNetwork($host, $network, $networkPlan);
            });

            $newInstances = [];

            foreach ($roles as $role) {
                $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
                $image = $this->imageNameFor($kind, $role);
                $ip = $networkPlan->ipForRole($role);

                $cycleTimer->measure("reset.start.{$role}", fn () => $this->startContainer($host, $name, $network, $ip, $image));

                $newInstances[$role] = new DockerInstance($host, $name, $network);
            }

            $cycleTimer->measure('reset.retarget', fn () => $this->retargetTopology($newInstances, $networkPlan));

            if ($options->startGatewayApi && isset($newInstances['gateway'])) {
                $cycleTimer->measure('reset.gateway-restart', fn () => E2EGatewayApi::start(
                    $newInstances['gateway'],
                    "topology-{$runId}",
                    gatewayIp: $networkPlan->ipForRole('gateway'),
                ));
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
            gatewayApiIp: $networkPlan->ipForRole('gateway'),
            resourceLease: $resourceLease,
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
    /**
     * @param  list<string>|null  $hostNames
     * @return array{host: DockerHost|null, failures: list<string>}
     */
    private function selectHost(E2ETopologyKind $kind, ?array $hostNames = null): array
    {
        $failures = [];
        $requestedContainers = count($this->rolesFor($kind));
        $hostNames ??= $this->dockerHostCandidates();

        if ($hostNames === []) {
            return ['host' => null, 'failures' => ['no Docker host is configured']];
        }

        foreach ($hostNames as $hostName) {
            $host = new DockerHost($this->config, $hostName);

            if (! $host->run('command -v docker >/dev/null', timeoutSeconds: 10)->successful()) {
                $failures[] = "{$hostName}: docker command is not available";

                continue;
            }

            if (! $host->run('docker info >/dev/null', timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds())->successful()) {
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

    /**
     * @return list<string>
     */
    private function dockerHostCandidates(): array
    {
        return $this->config->dockerHostSlots !== []
            ? array_keys($this->config->dockerHostSlots)
            : $this->config->dockerHosts;
    }

    private function dockerMetadataProbeTimeoutSeconds(): int
    {
        return min($this->config->timeoutSeconds, self::DockerMetadataProbeTimeoutSeconds);
    }

    private function acquireResourceLease(): ?E2EResourceLease
    {
        if ($this->config->dockerHostSlots === []) {
            return null;
        }

        return E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $this->config->slotWaitSeconds,
            staleSeconds: $this->config->slotStaleSeconds,
        )->acquire('docker', $this->config->dockerHostSlots, $this->config->exclusiveHosts);
    }

    private function missingImage(DockerHost $host, E2ETopologyKind $kind): ?string
    {
        foreach ($this->rolesFor($kind) as $role) {
            $image = $this->imageNameFor($kind, $role);

            if (! $host->run(sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)), timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds())->successful()) {
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
        ), timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds());

        if (! $result->successful()) {
            return $this->config->dockerMaxContainersPerHost + 1;
        }

        return count(array_filter(
            array_map(trim(...), explode("\n", $result->output())),
            fn (string $name): bool => str_starts_with($name, "{$this->config->instancePrefix}-"),
        ));
    }

    private function createNetwork(DockerHost $host, string $network, DockerTopologyNetworkPlan $networkPlan): void
    {
        $host->mustRun(
            sprintf('docker network create --subnet %s %s', escapeshellarg($networkPlan->subnet()), escapeshellarg($network)),
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

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function retargetTopology(array $instances, DockerTopologyNetworkPlan $networkPlan): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $gatewayIp = $networkPlan->ipForRole('gateway');
        $controlIp = $networkPlan->ipForRole('control');
        $key = new SshKeyPair('/dev/null', '/dev/null');

        E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
            'cd /home/orbit/orbit && if php artisan orbit:internal:bootstrap-gateway-local --help | grep -q -- --skip-runtime-install; then php artisan orbit:internal:bootstrap-gateway-local gateway %s --skip-runtime-install; else php artisan orbit:internal:bootstrap-gateway-local gateway %s; fi',
            escapeshellarg($gatewayIp),
            escapeshellarg($gatewayIp),
        ), timeoutSeconds: 120);
        E2EGatewayApi::seedControlIdentity($gateway, $controlIp, 'control', $gatewayIp, $controlIp);

        $this->retargetControl($control, $networkPlan, $key);

        if (isset($instances['dev'])) {
            E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-dev-1 --role=app --host=%s --wireguard-address=%s --environment=development --tld=test --gateway-endpoint=%s --user=orbit --user=orbit',
                escapeshellarg($networkPlan->ipForRole('dev')),
                escapeshellarg($networkPlan->ipForRole('dev')),
                escapeshellarg($gatewayIp),
            ), timeoutSeconds: 120);
        }

        if (isset($instances['prod'])) {
            E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-prod-1 --role=app --host=%s --wireguard-address=%s --environment=production --gateway-endpoint=%s --user=orbit --user=orbit',
                escapeshellarg($networkPlan->ipForRole('prod')),
                escapeshellarg($networkPlan->ipForRole('prod')),
                escapeshellarg($gatewayIp),
            ), timeoutSeconds: 120);
        }
    }

    private function retargetControl(DockerInstance $control, DockerTopologyNetworkPlan $networkPlan, SshKeyPair $key): void
    {
        $gatewayIpValue = var_export($networkPlan->ipForRole('gateway'), true);

        $php = <<<PHP
\\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'gateway'],
    array_merge(
        [
            'role' => 'gateway',
            'environment' => null,
            'tld' => null,
            'platform' => 'unknown',
            'host' => {$gatewayIpValue},
            'wireguard_address' => {$gatewayIpValue},
            'gateway_endpoint' => null,
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
        ],
        \\Illuminate\\Support\\Facades\\Schema::hasColumn('nodes', 'ssh_user') ? ['ssh_user' => 'orbit'] : [],
    ),
);

\\App\\Models\\LocalGatewaySettings::current()->fill([
    'gateway_url' => 'https://'.{$gatewayIpValue},
    'gateway_wg_ip' => {$gatewayIpValue},
])->save();
PHP;

        E2ECommand::ssh(
            $control,
            'control',
            $key,
            'cd /home/control/orbit && php artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 120,
        );
    }
}
