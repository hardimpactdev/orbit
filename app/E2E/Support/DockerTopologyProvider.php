<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;

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
        $imageNames = $selection['image_names'] ?? [];

        if ($host === null) {
            $resourceLease?->release();

            throw new \RuntimeException(implode('; ', $selection['failures']));
        }

        $network = "{$this->config->instancePrefix}-{$runId}";
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment();
        $topologyMode = $this->topologyMode();
        $roles = $this->rolesFor($kind);
        $instances = [];

        try {
            $timer->measure('docker.network', fn () => $this->createNetwork($host, $network, $networkPlan));

            $instances = $this->startContainers($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames, $timer, 'docker.start');

            if ($topologyMode === 'dns-alias') {
                $timer->measure('docker.primeGatewayApi', fn () => $this->primeGatewayApi($runId, $instances, $networkPlan));
            } else {
                $timer->measure('docker.retarget', fn () => $this->retargetTopology($instances, $networkPlan));
            }

            if ($topologyMode !== 'dns-alias' && $options->startGatewayApi && isset($instances['gateway'])) {
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

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $network, $roles, $options, $networkPlan, $topologyMode, $imageNames): array {
            $newInstances = $this->startContainers($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames, $cycleTimer, 'reset.start');

            if ($topologyMode === 'dns-alias') {
                $cycleTimer->measure('reset.primeGatewayApi', fn () => $this->primeGatewayApi($runId, $newInstances, $networkPlan));
            } else {
                $cycleTimer->measure('reset.retarget', fn () => $this->retargetTopology($newInstances, $networkPlan));
            }

            if ($topologyMode !== 'dns-alias' && $options->startGatewayApi && isset($newInstances['gateway'])) {
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

        $bulkCleanup = fn (E2EPhaseTimer $cleanupTimer) => $this->cleanupContainersForRoles($host, $roles, $runId, $cleanupTimer);
        $teardown = fn (E2EPhaseTimer $cleanupTimer) => $this->cleanupNetwork($host, $network, $cleanupTimer);

        return new E2ETopologyLease(
            kind: $kind,
            control: $instances['control'],
            gateway: $instances['gateway'] ?? null,
            dev: $instances['dev'] ?? null,
            prod: $instances['prod'] ?? null,
            sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
            rebuild: $rebuild,
            snapshotReset: null,
            bulkCleanup: $bulkCleanup,
            teardown: $teardown,
            gatewayApiIp: $networkPlan->ipForRole('gateway'),
            resourceLease: $resourceLease,
        );
    }

    public function imageNameFor(E2ETopologyKind $kind, string $role, ?string $mode = null): string
    {
        return DockerTopologyBuilder::imageNameFor($kind, $role, $mode ?? $this->topologyMode());
    }

    /**
     * @return list<string>
     */
    private function imageNameCandidatesFor(E2ETopologyKind $kind, string $role, ?string $mode = null): array
    {
        $mode ??= $this->topologyMode();

        return [
            $this->imageNameFor($kind, $role, $mode),
            ...array_map(
                fn (string $value): string => $this->imageNameForValue($value, $role, $mode),
                $kind->deprecatedValues(),
            ),
        ];
    }

    private function imageNameForValue(string $kind, string $role, string $mode): string
    {
        if ($mode === 'legacy-retarget') {
            return "orbit-e2e-topology:{$kind}-{$role}-current";
        }

        return "orbit-e2e-topology:{$kind}-{$role}-{$mode}-current";
    }

    /**
     * @return list<string>
     */
    public function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Operator => ['control'],
            E2ETopologyKind::OperatorGateway => ['control', 'gateway'],
            E2ETopologyKind::OperatorGatewayAppdev => ['control', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprod => ['control', 'gateway', 'dev', 'prod'],
        };
    }

    /**
     * @return array{host: DockerHost|null, failures: list<string>, image_names?: array<string, string>}
     */
    /**
     * @param  list<string>|null  $hostNames
     * @return array{host: DockerHost|null, failures: list<string>, image_names?: array<string, string>}
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

            $imageNames = [];
            $missingImage = $this->resolveImageNamesFor($host, $kind, $imageNames);

            if ($missingImage !== null) {
                $failures[] = "{$hostName}: docker prepared image {$missingImage} is not available";

                continue;
            }

            $runningContainers = $this->runningE2EContainerCount($host);

            if ($runningContainers + $requestedContainers > $this->config->dockerMaxContainersPerHost) {
                $failures[] = "{$hostName}: docker capacity exceeded";

                continue;
            }

            return ['host' => $host, 'failures' => $failures, 'image_names' => $imageNames];
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

    /**
     * @param  array<string, string>  $imageNames
     */
    private function resolveImageNamesFor(DockerHost $host, E2ETopologyKind $kind, array &$imageNames): ?string
    {
        foreach ($this->rolesFor($kind) as $role) {
            foreach ($this->imageNameCandidatesFor($kind, $role) as $image) {
                if ($host->run(sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)), timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds())->successful()) {
                    $imageNames[$role] = $image;

                    continue 2;
                }
            }

            return $this->imageNameFor($kind, $role);
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

    /**
     * @param  list<string>  $roles
     * @param  array<string, string>  $imageNames
     * @return array<string, DockerInstance>
     */
    private function startContainers(DockerHost $host, E2ETopologyKind $kind, string $runId, string $network, array $roles, DockerTopologyNetworkPlan $networkPlan, string $topologyMode, array $imageNames, E2EPhaseTimer $timer, string $timerPrefix): array
    {
        $tasks = [];

        foreach ($roles as $role) {
            $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
            $ip = $networkPlan->ipForRole($role);
            $image = $imageNames[$role] ?? $this->imageNameFor($kind, $role, $topologyMode);

            $tasks[$role] = [
                'name' => $name,
                'command' => $this->startContainerCommand($name, $network, $role, $ip, $image, $topologyMode),
            ];
        }

        try {
            if (! $this->parallelStartsEnabled()) {
                $instances = [];

                foreach ($tasks as $role => $task) {
                    $timer->measure(
                        "{$timerPrefix}.{$role}",
                        fn () => $host->mustRun($task['command'], "Could not start container {$task['name']}"),
                    );

                    $instances[$role] = new DockerInstance($host, $task['name'], $network);
                }

                return $instances;
            }

            $results = $timer->measure($timerPrefix, fn () => Process::pool(function ($pool) use ($host, $tasks): void {
                foreach ($tasks as $role => $task) {
                    $pool->as($role)
                        ->timeout($this->config->timeoutSeconds)
                        ->env($host->environment())
                        ->command($task['command']);
                }
            })->run());

            $instances = [];

            foreach ($tasks as $role => $task) {
                $result = $results[$role];

                if (! $result->successful()) {
                    throw new \RuntimeException("Could not start container {$task['name']}: ".trim($result->errorOutput().' '.$result->output()));
                }

                $instances[$role] = new DockerInstance($host, $task['name'], $network);
            }

            return $instances;
        } catch (\Throwable $throwable) {
            $this->cleanupContainers($host, array_column($tasks, 'name'));

            throw $throwable;
        }
    }

    private function parallelStartsEnabled(): bool
    {
        $value = getenv('ORBIT_E2E_DOCKER_PARALLEL_STARTS');

        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    private function startContainerCommand(string $name, string $network, string $role, string $ip, string $image, string $topologyMode): string
    {
        $networkAlias = $topologyMode === 'dns-alias'
            ? ' --network-alias '.escapeshellarg($role)
            : '';

        return sprintf(
            'docker run -d --name %s --network %s%s --ip %s --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s',
            escapeshellarg($name),
            escapeshellarg($network),
            $networkAlias,
            escapeshellarg($ip),
            escapeshellarg($image),
        );
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function primeGatewayApi(string $runId, array $instances, DockerTopologyNetworkPlan $networkPlan): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        E2EGatewayApi::start(
            $gateway,
            "topology-{$runId}",
            wireguardIdentity: '10.6.0.2',
            bindAddress: '0.0.0.0',
            certKey: 'gateway',
            certSans: ['10.6.0.2'],
            peerIdentityMap: $this->canonicalPeerIdentityMap($instances, $networkPlan),
        );
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     * @return array<string, string>
     */
    private function canonicalPeerIdentityMap(array $instances, DockerTopologyNetworkPlan $networkPlan): array
    {
        $canonical = [
            'gateway' => '10.6.0.2',
            'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
        ];

        $map = [];

        foreach (array_keys($instances) as $role) {
            if (! isset($canonical[$role])) {
                continue;
            }

            $map[$networkPlan->ipForRole($role)] = $canonical[$role];
        }

        return $map;
    }

    /**
     * @param  list<string>  $roles
     */
    private function cleanupResources(DockerHost $host, string $network, array $roles, string $runId, ?E2EPhaseTimer $timer = null): void
    {
        $containerNames = array_map(
            fn (string $role): string => "{$this->config->instancePrefix}-{$runId}-{$role}",
            $roles,
        );

        $this->cleanupContainers($host, $containerNames, $timer);
        $this->cleanupNetwork($host, $network, $timer);
    }

    /**
     * @param  list<string>  $roles
     */
    private function cleanupContainersForRoles(DockerHost $host, array $roles, string $runId, ?E2EPhaseTimer $timer = null): void
    {
        $this->cleanupContainers($host, array_map(
            fn (string $role): string => "{$this->config->instancePrefix}-{$runId}-{$role}",
            $roles,
        ), $timer);
    }

    /**
     * @param  list<string>  $containerNames
     */
    private function cleanupContainers(DockerHost $host, array $containerNames, ?E2EPhaseTimer $timer = null): void
    {
        $deleteContainers = fn () => $host->run(sprintf(
            'docker rm -f %s >/dev/null 2>&1 || true',
            implode(' ', array_map(escapeshellarg(...), $containerNames)),
        ), timeoutSeconds: 120);

        if ($timer !== null) {
            $timer->measure('cleanup.containers', $deleteContainers);

            return;
        }

        $deleteContainers();
    }

    private function cleanupNetwork(DockerHost $host, string $network, ?E2EPhaseTimer $timer = null): void
    {
        $deleteNetwork = fn () => $host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 30);

        if ($timer !== null) {
            $timer->measure('cleanup.network', $deleteNetwork);

            return;
        }

        $deleteNetwork();
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

    private function topologyMode(): string
    {
        return getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE') === 'dns-alias'
            ? 'dns-alias'
            : 'legacy-retarget';
    }
}
