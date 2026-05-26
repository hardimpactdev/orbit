<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;

final readonly class DockerTopologyProvider implements E2ETopologyProvider
{
    private const int DockerMetadataProbeTimeoutSeconds = 120;

    private const int ContainersPerRole = 1;

    private const int GatewayRuntimeContainers = 1;

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
        if (! E2EPreparedTopology::supportsKind($kind)) {
            return ProviderAvailability::unavailable(E2EPreparedTopology::unsupportedKindMessage($kind));
        }

        $selection = $this->selectHost(
            $kind,
            checkCapacity: $this->config->dockerHostSlots === [],
        );

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
        $topologyMode = $this->topologyMode();
        $roles = $this->rolesFor($kind);
        $instances = [];

        try {
            $networkPlan = $timer->measure('docker.network', fn (): DockerTopologyNetworkPlan => $this->createNetwork($host, $network, $runId));

            $instances = $this->startContainers($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames, $timer, 'docker.start');

            $timer->measure('docker.prune', fn () => $this->prunePreparedGatewayRegistry($instances));
            $timer->measure('docker.primeGatewayApi', fn () => $this->primeGatewayApi($runId, $instances, $networkPlan));
        } catch (\Throwable $exception) {
            $this->cleanupResources($host, $network, $roles, $runId);
            $resourceLease?->release();

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames): array {
            $newInstances = $this->startContainers($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames, $cycleTimer, 'reset.start');

            $cycleTimer->measure('reset.prune', fn () => $this->prunePreparedGatewayRegistry($newInstances));
            $cycleTimer->measure('reset.primeGatewayApi', fn () => $this->primeGatewayApi($runId, $newInstances, $networkPlan));

            return [
                'instances' => $this->leaseInstancesFor($kind, $newInstances),
                'snapshotReset' => null,
            ];
        };

        $bulkCleanup = fn (E2EPhaseTimer $cleanupTimer) => $this->cleanupContainersForRoles($host, $roles, $runId, $cleanupTimer);
        $teardown = fn (E2EPhaseTimer $cleanupTimer) => $this->cleanupNetwork($host, $network, $cleanupTimer);
        $leaseInstances = $this->leaseInstancesFor($kind, $instances);

        return new E2ETopologyLease(
            kind: $kind,
            control: $leaseInstances['operator'] ?? $leaseInstances['control'],
            gateway: $leaseInstances['gateway'] ?? null,
            dev: $leaseInstances['dev'] ?? null,
            prod: $leaseInstances['prod'] ?? null,
            sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
            rebuild: $rebuild,
            snapshotReset: null,
            bulkCleanup: $bulkCleanup,
            teardown: $teardown,
            gatewayApiIp: $networkPlan->ipForRole('gateway'),
            resourceLease: $resourceLease,
            agent: $leaseInstances['agent'] ?? null,
            ingress: $leaseInstances['ingress'] ?? null,
            additionalInstances: $this->additionalInstancesFrom($leaseInstances),
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

        $sourceKind = E2EPreparedTopology::dockerSourceKindFor($kind);
        $sourceRole = $role === 'control' ? 'operator' : $role;

        return [$this->imageNameFor($sourceKind, $sourceRole, $mode)];
    }

    /**
     * @return list<string>
     */
    public function rolesFor(E2ETopologyKind $kind): array
    {
        return E2EPreparedTopology::runtimeRolesFor($kind, self::rolesForKind($kind));
    }

    /**
     * @return list<string>
     */
    public static function rolesForKind(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Operator => ['operator'],
            E2ETopologyKind::OperatorGateway => ['operator', 'gateway'],
            E2ETopologyKind::OperatorGatewayAppdev => ['operator', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprod => ['operator', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAgent => ['operator', 'gateway', 'agent'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['operator', 'gateway', 'dev', 'prod', 'agent'],
            E2ETopologyKind::OperatorGatewayAppprodIngress => ['operator', 'gateway', 'prod', 'ingress'],
        };
    }

    public static function containerCountFor(E2ETopologyKind $kind): int
    {
        return self::containerCountForRoles(E2EPreparedTopology::runtimeRolesFor($kind, self::rolesForKind($kind)));
    }

    public static function maxContainerCountForAnyTopology(): int
    {
        return max(array_map(self::containerCountFor(...), E2ETopologyKind::cases()));
    }

    public static function runtimeSiblingImage(): string
    {
        return E2ETopologyArtifactNamespace::dockerRuntimeImage('orbit-runtime');
    }

    /**
     * @param  list<string>|null  $hostNames
     * @return array{host: DockerHost|null, failures: list<string>, image_names?: array<string, string>}
     */
    private function selectHost(E2ETopologyKind $kind, ?array $hostNames = null, bool $checkCapacity = true): array
    {
        $failures = [];
        $roles = $this->rolesFor($kind);
        $requestedContainers = self::containerCountForRoles($roles);
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

            if (self::rolesNeedRuntimeSibling($roles) && ! $this->hasRuntimeSiblingImage($host)) {
                $failures[] = "{$hostName}: docker runtime image ".self::runtimeSiblingImage().' is not available';

                continue;
            }

            $missingImage = $this->resolveImageNamesFor($host, $kind, $imageNames);

            if ($missingImage !== null) {
                $failures[] = "{$hostName}: docker prepared image {$missingImage} is not available";

                continue;
            }

            if (! $checkCapacity) {
                return ['host' => $host, 'failures' => $failures, 'image_names' => $imageNames];
            }

            try {
                $containerCap = $this->config->dockerMaxContainersForHost($hostName);
            } catch (\InvalidArgumentException $exception) {
                $failures[] = "{$hostName}: {$exception->getMessage()}";

                continue;
            }

            $runningContainers = $this->runningE2EContainerCount($host);

            if ($runningContainers + $requestedContainers > $containerCap) {
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
            $imageCandidates = $this->imageNameCandidatesFor($kind, $role);

            foreach ($imageCandidates as $image) {
                if ($host->run(sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)), timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds())->successful()) {
                    $imageNames[$role] = $image;

                    continue 2;
                }
            }

            return $imageCandidates[0] ?? $this->imageNameFor($kind, $role);
        }

        return null;
    }

    private function hasRuntimeSiblingImage(DockerHost $host): bool
    {
        return $host->run(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg(self::runtimeSiblingImage())),
            timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds(),
        )->successful();
    }

    private function runningE2EContainerCount(DockerHost $host): int
    {
        $result = $host->run(sprintf(
            'docker ps --format %s --filter %s',
            escapeshellarg('{{.Names}}'),
            escapeshellarg("name={$this->config->instancePrefix}-"),
        ), timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds());

        if (! $result->successful()) {
            return PHP_INT_MAX;
        }

        return count(array_filter(
            array_map(trim(...), explode("\n", $result->output())),
            fn (string $name): bool => str_starts_with($name, "{$this->config->instancePrefix}-"),
        ));
    }

    private function createNetwork(DockerHost $host, string $network, string $runId): DockerTopologyNetworkPlan
    {
        $token = getenv('TEST_TOKEN');
        $maxAttempts = is_string($token) && $token !== '' ? 16 : 224;
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $networkPlan = DockerTopologyNetworkPlan::fromEnvironment($runId, $attempt);
            $result = $host->run(
                sprintf('docker network create --subnet %s %s', escapeshellarg($networkPlan->subnet()), escapeshellarg($network)),
                60,
            );

            if ($result->successful()) {
                return $networkPlan;
            }

            $lastError = trim($result->errorOutput().' '.$result->output());

            if (! str_contains($lastError, 'Pool overlaps')) {
                throw new \RuntimeException("Could not create Docker network {$network}: {$lastError}");
            }
        }

        throw new \RuntimeException("Could not create Docker network {$network}: {$lastError}");
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
                'container_names' => $this->managedContainerNames($name, $role),
                'volume_names' => $this->managedVolumeNames($name),
                'node_command' => $this->startContainerCommand($name, $network, $role, $ip, $image, $topologyMode),
                'runtime_command' => $this->startsRuntimeSibling($role)
                    ? $this->startRuntimeContainerCommand($name, $network, $role)
                    : null,
            ];
            $tasks[$role]['command'] = implode(' && ', array_filter([
                $tasks[$role]['node_command'],
                $tasks[$role]['runtime_command'],
            ]));
        }

        try {
            if (! $this->parallelStartsEnabled()) {
                $instances = [];

                foreach ($tasks as $role => $task) {
                    $timer->measure(
                        "{$timerPrefix}.{$role}",
                        function () use ($host, $task): void {
                            $host->mustRun($task['node_command'], "Could not start container {$task['name']}");

                            if (is_string($task['runtime_command'])) {
                                $host->mustRun($task['runtime_command'], "Could not start runtime container {$this->runtimeContainerName($task['name'])}");
                            }
                        },
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
            $this->cleanupContainers($host, array_merge(...array_column($tasks, 'container_names')));
            $this->cleanupVolumes($host, array_merge(...array_column($tasks, 'volume_names')));

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
        $runtimeContainerEnv = $this->startsRuntimeSibling($role)
            ? ' --env '.escapeshellarg("ORBIT_RUNTIME_CONTAINER={$this->runtimeContainerName($name)}")
            : '';

        return sprintf(
            'docker run -d --name %s --network %s%s --ip %s --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s --volume %s --mount %s --env %s%s %s',
            escapeshellarg($name),
            escapeshellarg($network),
            $networkAlias,
            escapeshellarg($ip),
            $this->dockerSocketGroupAddOption(),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg($this->homeVolumeMount($name, 'orbit')),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            $runtimeContainerEnv,
            escapeshellarg($image),
        );
    }

    private function dockerSocketGroupAddOption(): string
    {
        return '--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"';
    }

    private function startRuntimeContainerCommand(string $nodeContainer, string $network, string $role): string
    {
        $orbitPath = $this->orbitPathForRole($role);
        $gatewayEnv = $role === 'gateway'
            ? ' --env '.escapeshellarg('ORBIT_IS_GATEWAY=1')
            : '';

        return sprintf(
            'docker run -d --restart unless-stopped --name %s --network %s --volume %s --mount %s --env %s --env %s --env %s%s --workdir %s %s',
            escapeshellarg($this->runtimeContainerName($nodeContainer)),
            escapeshellarg("container:{$nodeContainer}"),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg($this->homeVolumeMount($nodeContainer, 'orbit')),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            escapeshellarg("ORBIT_NODE_CONTAINER={$nodeContainer}"),
            escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
            $gatewayEnv,
            escapeshellarg($orbitPath),
            escapeshellarg(self::runtimeSiblingImage()),
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
            certSans: ['10.6.0.2', 'gateway'],
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
            'operator' => '10.6.0.3',
            'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            'ingress' => '10.6.0.7',
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
        $this->cleanupContainers($host, $this->containerNamesForRoles($roles, $runId), $timer);
        $this->cleanupVolumes($host, $this->volumeNamesForRoles($roles, $runId), $timer);
        $this->cleanupNetwork($host, $network, $timer);
    }

    /**
     * @param  list<string>  $roles
     */
    private function cleanupContainersForRoles(DockerHost $host, array $roles, string $runId, ?E2EPhaseTimer $timer = null): void
    {
        $this->cleanupContainers($host, $this->containerNamesForRoles($roles, $runId), $timer);
        $this->cleanupVolumes($host, $this->volumeNamesForRoles($roles, $runId), $timer);
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
     * @param  list<string>  $volumeNames
     */
    private function cleanupVolumes(DockerHost $host, array $volumeNames, ?E2EPhaseTimer $timer = null): void
    {
        $deleteVolumes = fn () => $host->run(sprintf(
            'docker volume rm -f %s >/dev/null 2>&1 || true',
            implode(' ', array_map(escapeshellarg(...), $volumeNames)),
        ), timeoutSeconds: 120);

        if ($timer !== null) {
            $timer->measure('cleanup.volumes', $deleteVolumes);

            return;
        }

        $deleteVolumes();
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function prunePreparedGatewayRegistry(array $instances, ?SshKeyPair $key = null): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        $key ??= new SshKeyPair('/dev/null', '/dev/null');
        $php = E2EPreparedTopology::gatewayRegistryPrunePhp(
            E2EPreparedTopology::gatewayNodeNamesForRoles(array_keys($instances)),
        );

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 120,
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function containerNamesForRoles(array $roles, string $runId): array
    {
        $names = [];

        foreach ($roles as $role) {
            array_push(
                $names,
                ...$this->managedContainerNames("{$this->config->instancePrefix}-{$runId}-{$role}", $role),
            );
        }

        return $names;
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function volumeNamesForRoles(array $roles, string $runId): array
    {
        $names = [];

        foreach ($roles as $role) {
            array_push(
                $names,
                ...$this->managedVolumeNames("{$this->config->instancePrefix}-{$runId}-{$role}"),
            );
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function managedContainerNames(string $nodeContainer, string $role): array
    {
        $names = [
            "{$nodeContainer}-orbit-caddy",
            $nodeContainer,
        ];

        if ($this->startsRuntimeSibling($role)) {
            array_unshift($names, $this->runtimeContainerName($nodeContainer));
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function managedVolumeNames(string $nodeContainer): array
    {
        return [
            $this->homeVolumeName($nodeContainer, 'orbit'),
        ];
    }

    private function homeVolumeMount(string $nodeContainer, string $user): string
    {
        return "type=volume,src={$this->homeVolumeName($nodeContainer, $user)},dst=/home/{$user}";
    }

    private function homeVolumeName(string $nodeContainer, string $user): string
    {
        return "{$nodeContainer}-home-{$user}";
    }

    private function runtimeContainerName(string $nodeContainer): string
    {
        return "{$nodeContainer}-orbit-runtime";
    }

    private function startsRuntimeSibling(string $role): bool
    {
        return $role === 'gateway';
    }

    /**
     * @param  list<string>  $roles
     */
    private static function containerCountForRoles(array $roles): int
    {
        return (count($roles) * self::ContainersPerRole)
            + (self::rolesNeedRuntimeSibling($roles) ? self::GatewayRuntimeContainers : 0);
    }

    /**
     * @param  list<string>  $roles
     */
    private static function rolesNeedRuntimeSibling(array $roles): bool
    {
        return in_array('gateway', $roles, true);
    }

    private function orbitPathForRole(string $role): string
    {
        return '/home/orbit/orbit';
    }

    private function topologyMode(): string
    {
        return 'dns-alias';
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     * @return array<string, DockerInstance>
     */
    private function leaseInstancesFor(E2ETopologyKind $kind, array $instances): array
    {
        if (E2EPreparedTopology::prodHostsIngressRole($kind) && isset($instances['prod']) && ! isset($instances['ingress'])) {
            $instances['ingress'] = $instances['prod'];
        }

        return $instances;
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     * @return array<string, DockerInstance>
     */
    private function additionalInstancesFrom(array $instances): array
    {
        return array_diff_key($instances, array_flip(['operator', 'control', 'gateway', 'dev', 'prod', 'agent', 'ingress']));
    }
}
