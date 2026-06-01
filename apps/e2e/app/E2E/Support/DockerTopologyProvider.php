<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Support\Facades\Process;

final readonly class DockerTopologyProvider implements E2ETopologyProvider
{
    private const int DockerMetadataProbeTimeoutSeconds = 120;

    private const int ContainersPerRole = 1;

    private const int GatewayRuntimeContainers = 1;

    private const string OrbitPath = '/home/orbit/orbit';

    private const string OrbitConfigRoot = '/home/orbit/.config/orbit';

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

            $timer->measure('docker.seedRuntimeNameShim', fn () => $this->seedRuntimeContainerNameShim($instances));
            $timer->measure('docker.seedSshAccess', fn () => $this->seedRemoteShellSshAccess($instances));
            $timer->measure('docker.seedOperatorIdentity', fn () => $this->seedOperatorIdentity($instances, $networkPlan, $topologyMode));
            $timer->measure('docker.seedGatewayRegistry', fn () => $this->seedGatewayRegistry($kind, $instances, $networkPlan, $topologyMode));
            $timer->measure('docker.prune', fn () => $this->prunePreparedGatewayRegistry($instances));
            $timer->measure('docker.primeGatewayApi', fn () => $this->primeGatewayApi($runId, $instances, $networkPlan));
        } catch (\Throwable $exception) {
            $this->cleanupResources($host, $network, $roles, $runId);
            $resourceLease?->release();

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames): array {
            $newInstances = $this->startContainers($host, $kind, $runId, $network, $roles, $networkPlan, $topologyMode, $imageNames, $cycleTimer, 'reset.start');

            $cycleTimer->measure('reset.seedRuntimeNameShim', fn () => $this->seedRuntimeContainerNameShim($newInstances));
            $cycleTimer->measure('reset.seedSshAccess', fn () => $this->seedRemoteShellSshAccess($newInstances));
            $cycleTimer->measure('reset.seedOperatorIdentity', fn () => $this->seedOperatorIdentity($newInstances, $networkPlan, $topologyMode));
            $cycleTimer->measure('reset.seedGatewayRegistry', fn () => $this->seedGatewayRegistry($kind, $newInstances, $networkPlan, $topologyMode));
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
            operator: $leaseInstances['operator'] ?? $leaseInstances['operator'],
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

        $candidate = $this->imageNameFor($kind, $role, $mode);
        $baseCandidate = DockerTopologyBuilder::baseImageNameFor($kind, $role, $mode);

        if ($candidate === $baseCandidate) {
            return [$candidate];
        }

        return [$candidate, $baseCandidate];
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
            E2ETopologyKind::OperatorGatewayAppdevAppprodIngress => ['operator', 'gateway', 'dev', 'prod', 'ingress'],
            E2ETopologyKind::OperatorGatewayAgent => ['operator', 'gateway', 'agent'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['operator', 'gateway', 'dev', 'prod', 'agent'],
            E2ETopologyKind::OperatorGatewayAppprodIngress => ['operator', 'gateway', 'prod', 'ingress'],
            E2ETopologyKind::OperatorGatewayAppdevWebsocket => ['operator', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket => ['operator', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket => ['operator', 'gateway', 'dev', 'prod', 'agent'],
        };
    }

    public static function containerCountFor(E2ETopologyKind $kind): int
    {
        return self::containerCountForRoles(E2EPreparedTopology::runtimeRolesFor($kind, self::rolesForKind($kind)));
    }

    private static function websocketTopologyKind(E2ETopologyKind $kind): bool
    {
        return in_array($kind, [
            E2ETopologyKind::OperatorGatewayAppdevWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        ], true);
    }

    public static function maxContainerCountForAnyTopology(): int
    {
        return max(array_map(self::containerCountFor(...), E2ETopologyKind::cases()));
    }

    public static function runtimeSiblingImage(): string
    {
        return E2ETopologyArtifactNamespace::dockerRuntimeImage('orbit-runtime');
    }

    public static function defaultPhpRuntimeImage(): string
    {
        return (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT);
    }

    /**
     * @return list<string>
     */
    public static function phpRuntimeImages(): array
    {
        return (new PhpRuntimeCatalog)->supportedImages();
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

            if ($this->sourcePathForHost($host) === null) {
                $failures[] = "{$hostName}: source-mounted Docker topologies require ORBIT_E2E_DOCKER_SOURCE_PATH for remote Docker hosts";

                continue;
            }

            $imageNames = [];

            if (self::rolesNeedRuntimeSibling($roles) && ! $this->hasRuntimeSiblingImage($host)) {
                $failures[] = "{$hostName}: docker runtime image ".self::runtimeSiblingImage().' is not available';

                continue;
            }

            if (! $this->hasOrbitCaddyImage($host)) {
                $failures[] = "{$hostName}: Docker orbit-caddy image ".OrbitCaddyContainer::Image.' is not available';

                continue;
            }

            $missingPhpRuntimeImage = self::rolesNeedPhpRuntimeImage($roles)
                ? $this->missingPhpRuntimeImage($host)
                : null;

            if ($missingPhpRuntimeImage !== null) {
                $failures[] = "{$hostName}: Docker PHP runtime image {$missingPhpRuntimeImage} is not available";

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

    private function hasOrbitCaddyImage(DockerHost $host): bool
    {
        return $host->run(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg(OrbitCaddyContainer::Image)),
            timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds(),
        )->successful();
    }

    private function missingPhpRuntimeImage(DockerHost $host): ?string
    {
        foreach (self::phpRuntimeImages() as $image) {
            if ($host->run(
                sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)),
                timeoutSeconds: $this->dockerMetadataProbeTimeoutSeconds(),
            )->successful()) {
                continue;
            }

            return $image;
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
        $maxAttempts = is_string($token) && $token !== ''
            ? DockerTopologyNetworkPlan::runScopedAttemptsPerWorker()
            : 224;
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
                'node_command' => implode(' && ', array_filter([
                    $this->startContainerCommand($host, $name, $network, $role, $ip, $image, $topologyMode),
                    $this->canonicalWireGuardAddressCommand($name, $role, $topologyMode),
                ])),
                'runtime_command' => $this->startsRuntimeSibling($role)
                    ? $this->startRuntimeContainerCommand($host, $name, $network, $role)
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

    private function startContainerCommand(DockerHost $host, string $name, string $network, string $role, string $ip, string $image, string $topologyMode): string
    {
        $networkAlias = $topologyMode === 'dns-alias'
            ? ' --network-alias '.escapeshellarg($role)
            : '';
        $runtimeContainerEnv = $this->startsRuntimeSibling($role)
            ? ' --env '.escapeshellarg("ORBIT_RUNTIME_CONTAINER={$this->runtimeContainerName($name)}")
            : '';
        $sourcePath = $this->sourcePathForHost($host)
            ?? throw new \RuntimeException("Source-mounted Docker topology source path is not configured for {$host->host}.");

        return sprintf(
            'docker run -d --name %s --network %s%s --ip %s --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s --volume %s --mount %s --mount %s --mount %s --mount %s --mount %s --env %s --env %s --env %s%s %s',
            escapeshellarg($name),
            escapeshellarg($network),
            $networkAlias,
            escapeshellarg($ip),
            $this->dockerSocketGroupAddOption(),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg($this->homeVolumeMount($name, 'orbit')),
            escapeshellarg($this->sourceBindMount($sourcePath)),
            escapeshellarg($this->nodeVolumeMount($name, 'etc-caddy', '/etc/caddy')),
            escapeshellarg($this->nodeVolumeMount($name, 'etc-orbit', '/etc/orbit')),
            escapeshellarg($this->nodeVolumeMount($name, 'opt-orbit', '/opt/orbit')),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            escapeshellarg("ORBIT_NODE_CONTAINER={$name}"),
            escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
            $runtimeContainerEnv,
            escapeshellarg($image),
        );
    }

    private function dockerSocketGroupAddOption(): string
    {
        return '--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"';
    }

    private function canonicalWireGuardAddressCommand(string $name, string $role, string $topologyMode): ?string
    {
        if ($topologyMode !== 'dns-alias') {
            return null;
        }

        $address = $this->canonicalWireGuardAddressForRole($role).'/24';

        return sprintf(
            'docker exec %s sh -lc %s',
            escapeshellarg($name),
            escapeshellarg(sprintf(
                'ip -o addr show dev eth0 | grep -Fqw %s || ip addr add %s dev eth0',
                escapeshellarg($address),
                escapeshellarg($address),
            )),
        );
    }

    private function startRuntimeContainerCommand(DockerHost $host, string $nodeContainer, string $network, string $role): string
    {
        $orbitPath = $this->orbitPathForRole($role);
        $gatewayEnv = $role === 'gateway'
            ? ' --env '.escapeshellarg('ORBIT_IS_GATEWAY=1')
            : '';
        $sourcePath = $this->sourcePathForHost($host)
            ?? throw new \RuntimeException("Source-mounted Docker topology source path is not configured for {$host->host}.");

        return sprintf(
            'docker run -d --restart unless-stopped --name %s --network %s --volume %s --mount %s --mount %s --env %s --env %s --env %s --env %s%s --workdir %s %s',
            escapeshellarg($this->runtimeContainerName($nodeContainer)),
            escapeshellarg("container:{$nodeContainer}"),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg($this->homeVolumeMount($nodeContainer, 'orbit')),
            escapeshellarg($this->sourceBindMount($sourcePath)),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            escapeshellarg("ORBIT_NODE_CONTAINER={$nodeContainer}"),
            escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
            escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
            $gatewayEnv,
            escapeshellarg($orbitPath),
            escapeshellarg(self::runtimeSiblingImage()),
        );
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function seedRuntimeContainerNameShim(array $instances): void
    {
        foreach ($instances as $instance) {
            $runtimeContainer = $this->runtimeContainerName($instance->name());
            $nodeContainer = $instance->name();

            E2ECommand::exec(
                $instance,
                str_replace([
                    '__RUNTIME_CONTAINER__',
                    '__NODE_CONTAINER__',
                    '__RUNTIME_IMAGE__',
                    '__ORBIT_PATH__',
                ], [
                    $runtimeContainer,
                    $nodeContainer,
                    self::runtimeSiblingImage(),
                    self::OrbitPath,
                ], <<<'SH_WRAP'
            if [ -x /usr/bin/docker ] && ! grep -q ORBIT_E2E_RUNTIME_DOCKER_SHIM /usr/bin/docker 2>/dev/null; then
                mv /usr/bin/docker /usr/bin/docker.real
            fi
            cat > /usr/local/bin/docker <<'BASH'
            #!/usr/bin/env bash
            # ORBIT_E2E_RUNTIME_DOCKER_SHIM
            set -eu

            real_docker="/usr/bin/docker.real"
            runtime_container="${ORBIT_RUNTIME_CONTAINER:-__RUNTIME_CONTAINER__}"
            node_container="${ORBIT_NODE_CONTAINER:-__NODE_CONTAINER__}"
            runtime_image="${ORBIT_RUNTIME_IMAGE:-__RUNTIME_IMAGE__}"
            source_path="${ORBIT_SOURCE_PATH:-__ORBIT_PATH__}"

            volume_mountpoint() {
                "${real_docker}" volume inspect "$1" --format '{{ .Mountpoint }}' 2>/dev/null || true
            }

            rewrite_path() {
                local path="$1"
                local etc_caddy
                local etc_orbit
                local opt_orbit

                etc_caddy="$(volume_mountpoint "${node_container}-etc-caddy")"
                etc_orbit="$(volume_mountpoint "${node_container}-etc-orbit")"
                opt_orbit="$(volume_mountpoint "${node_container}-opt-orbit")"

                case "${path}" in
                    /etc/caddy)
                        printf '%s' "${etc_caddy}"
                        ;;
                    /etc/caddy/*)
                        printf '%s%s' "${etc_caddy}" "${path#/etc/caddy}"
                        ;;
                    /etc/orbit)
                        printf '%s' "${etc_orbit}"
                        ;;
                    /etc/orbit/*)
                        printf '%s%s' "${etc_orbit}" "${path#/etc/orbit}"
                        ;;
                    /opt/orbit)
                        printf '%s' "${opt_orbit}"
                        ;;
                    /opt/orbit/*)
                        printf '%s%s' "${opt_orbit}" "${path#/opt/orbit}"
                        ;;
                    *)
                        printf '%s' "${path}"
                        ;;
                esac
            }

            rewrite_mount() {
                local argument="$1"
                local prefix
                local remainder
                local source
                local rest

                case "${argument}" in
                    type=bind,source=*)
                        prefix="type=bind,source="
                        ;;
                    type=bind,src=*)
                        prefix="type=bind,src="
                        ;;
                    *)
                        printf '%s' "${argument}"
                        return
                        ;;
                esac

                remainder="${argument#"${prefix}"}"
                source="${remainder%%,*}"
                rest="${remainder#"${source}"}"
                printf '%s%s%s' "${prefix}" "$(rewrite_path "${source}")" "${rest}"
            }

            args=()

            for argument in "$@"; do
                case "${argument}" in
                    orbit-runtime)
                        args+=("${runtime_container}")
                        ;;
                    orbit-runtime:current)
                        args+=("${runtime_image}")
                        ;;
                    /opt/orbit)
                        args+=("${source_path}")
                        ;;
                    /opt/orbit/*)
                        args+=("${source_path}${argument#/opt/orbit}")
                        ;;
                    type=bind,source=*|type=bind,src=*)
                        args+=("$(rewrite_mount "${argument}")")
                        ;;
                    *)
                        args+=("${argument}")
                        ;;
                esac
            done

            exec "${real_docker}" "${args[@]}"
            BASH
            chmod 0755 /usr/local/bin/docker
            cp /usr/local/bin/docker /usr/bin/docker
            SH_WRAP),
                "Could not install Docker runtime container name shim in Docker container {$instance->name()}",
                timeoutSeconds: 60,
            );
        }
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function seedRemoteShellSshAccess(array $instances): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        $targets = array_intersect_key($instances, array_flip(['dev', 'prod', 'agent', 'ingress', 'websocket']));

        if ($targets === []) {
            return;
        }

        $publicKey = $this->gatewayPublicKey($gateway);

        foreach ($targets as $role => $instance) {
            $this->authorizeGatewaySshKey($instance, $publicKey, $role);
        }
    }

    private function gatewayPublicKey(DockerInstance $gateway): string
    {
        $result = E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            'install -d -m 700 ~/.ssh && if ! test -f ~/.ssh/id_ed25519; then ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C orbit-e2e-gateway >/dev/null; fi && cat ~/.ssh/id_ed25519.pub',
            timeoutSeconds: 60,
        );

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new \RuntimeException('Could not create Docker gateway SSH key for RemoteShell E2E access.');
        }

        return $publicKey;
    }

    private function authorizeGatewaySshKey(DockerInstance $instance, string $publicKey, string $role): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'install -d -m 700 -o orbit -g orbit /home/orbit/.ssh && touch /home/orbit/.ssh/authorized_keys && chown orbit:orbit /home/orbit/.ssh/authorized_keys && chmod 600 /home/orbit/.ssh/authorized_keys && grep -qxF %1$s /home/orbit/.ssh/authorized_keys || printf "%%s\n" %1$s >> /home/orbit/.ssh/authorized_keys',
                escapeshellarg($publicKey),
            ),
            "Could not authorize gateway SSH key in Docker {$role} container",
            timeoutSeconds: 60,
        );
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function seedOperatorIdentity(array $instances, DockerTopologyNetworkPlan $networkPlan, string $mode): void
    {
        $operator = $instances['operator'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($operator === null || $gateway === null) {
            return;
        }

        E2EGatewayApi::seedOperatorIdentity(
            $gateway,
            $this->hostForRole('operator', $networkPlan, $mode),
            'orbit',
            $this->gatewayEndpoint($networkPlan, $mode),
            $this->wireGuardAddressForRole('operator', $networkPlan, $mode),
        );
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     */
    private function seedGatewayRegistry(E2ETopologyKind $kind, array $instances, DockerTopologyNetworkPlan $networkPlan, string $mode): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        $commands = $this->gatewayRegistrySeedCommands($kind, $instances, $networkPlan, $mode);

        if ($commands === []) {
            return;
        }

        E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            'cd /home/orbit/orbit && '.implode(' && ', $commands),
            timeoutSeconds: 300,
        );
    }

    /**
     * @param  array<string, DockerInstance>  $instances
     * @return list<string>
     */
    private function gatewayRegistrySeedCommands(E2ETopologyKind $kind, array $instances, DockerTopologyNetworkPlan $networkPlan, string $mode): array
    {
        $commands = [];
        $gatewayEndpoint = $this->gatewayEndpoint($networkPlan, $mode);

        if (isset($instances['dev'])) {
            $commands[] = sprintf(
                'php apps/gateway/artisan orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit',
                $this->hostForRole('dev', $networkPlan, $mode),
                $this->hostKeyHostOption('dev', $networkPlan, $mode),
                $this->wireGuardAddressForRole('dev', $networkPlan, $mode),
                $gatewayEndpoint,
            );
            $commands[] = 'php apps/gateway/artisan tinker --execute='.escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp());
        }

        if (isset($instances['ingress'])) {
            $commands[] = sprintf(
                'php apps/gateway/artisan orbit:internal:bake-ingress-node edge-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                $this->hostForRole('ingress', $networkPlan, $mode),
                $this->hostKeyHostOption('ingress', $networkPlan, $mode),
                $this->wireGuardAddressForRole('ingress', $networkPlan, $mode),
                $gatewayEndpoint,
            );
        }

        if (isset($instances['prod'])) {
            $prodHostsIngress = E2EPreparedTopology::prodHostsIngressRole($kind) && ! isset($instances['ingress']);

            if ($prodHostsIngress) {
                $commands[] = sprintf(
                    'php apps/gateway/artisan orbit:internal:bake-ingress-node app-prod-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                    $this->hostForRole('prod', $networkPlan, $mode),
                    $this->hostKeyHostOption('prod', $networkPlan, $mode),
                    $this->wireGuardAddressForRole('prod', $networkPlan, $mode),
                    $gatewayEndpoint,
                );
            }

            $ingressNode = match (true) {
                isset($instances['ingress']) => 'edge-1',
                $prodHostsIngress => 'app-prod-1',
                default => null,
            };
            $ingress = $ingressNode !== null ? " --ingress-node={$ingressNode}" : '';

            $commands[] = sprintf(
                'php apps/gateway/artisan orbit:internal:bake-app-node app-prod-1 --role=app-prod --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
                $this->hostForRole('prod', $networkPlan, $mode),
                $this->hostKeyHostOption('prod', $networkPlan, $mode),
                $this->wireGuardAddressForRole('prod', $networkPlan, $mode),
                $gatewayEndpoint,
                $ingress,
            );
        }

        if (isset($instances['agent'])) {
            $commands[] = sprintf(
                'php apps/gateway/artisan orbit:internal:bake-agent-node agent-1 --host=%s%s --wireguard-address=%s --tld=agent --gateway-endpoint=%s --user=orbit',
                $this->hostForRole('agent', $networkPlan, $mode),
                $this->hostKeyHostOption('agent', $networkPlan, $mode),
                $this->wireGuardAddressForRole('agent', $networkPlan, $mode),
                $gatewayEndpoint,
            );
        }

        if (self::websocketTopologyKind($kind) && isset($instances['dev'])) {
            $commands[] = sprintf(
                'php apps/gateway/artisan orbit:internal:bake-websocket-node app-dev-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --redis-node=app-dev-1',
                $this->hostForRole('dev', $networkPlan, $mode),
                $this->hostKeyHostOption('dev', $networkPlan, $mode),
                $this->wireGuardAddressForRole('dev', $networkPlan, $mode),
                $gatewayEndpoint,
            );
        }

        return $commands;
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
        $map = [];

        foreach (array_keys($instances) as $role) {
            $map[$networkPlan->ipForRole($role)] = $this->canonicalWireGuardAddressForRole($role);
        }

        return $map;
    }

    private function hostForRole(string $role, DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? $role
            : $networkPlan->ipForRole($role);
    }

    private function hostKeyHostOption(string $role, DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? ' --host-key-host='.$networkPlan->ipForRole($role)
            : '';
    }

    private function wireGuardAddressForRole(string $role, DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? $this->canonicalWireGuardAddressForRole($role)
            : $networkPlan->ipForRole($role);
    }

    private function gatewayEndpoint(DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? 'gateway'
            : $networkPlan->ipForRole('gateway');
    }

    private function canonicalWireGuardAddressForRole(string $role): string
    {
        return match ($role) {
            'gateway' => '10.6.0.2',
            'operator' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            'agent' => '10.6.0.6',
            'ingress' => '10.6.0.7',
            'websocket' => '10.6.0.8',
            default => throw new \RuntimeException("Unknown Docker topology role {$role}."),
        };
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
            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
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
            "{$nodeContainer}-etc-caddy",
            "{$nodeContainer}-etc-orbit",
            "{$nodeContainer}-opt-orbit",
        ];
    }

    private function homeVolumeMount(string $nodeContainer, string $user): string
    {
        return "type=volume,src={$this->homeVolumeName($nodeContainer, $user)},dst=/home/{$user}";
    }

    private function sourcePathForHost(DockerHost $host): ?string
    {
        $hostSpecificPath = getenv('ORBIT_E2E_DOCKER_SOURCE_PATH_'.self::sourcePathEnvironmentSuffix($host->host));

        if (is_string($hostSpecificPath) && trim($hostSpecificPath) !== '') {
            return trim($hostSpecificPath);
        }

        $sourcePath = getenv('ORBIT_E2E_DOCKER_SOURCE_PATH');

        if (is_string($sourcePath) && trim($sourcePath) !== '') {
            return trim($sourcePath);
        }

        return $host->isLocal() ? repo_path() : null;
    }

    private static function sourcePathEnvironmentSuffix(string $host): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $host));
    }

    private function sourceBindMount(string $sourcePath): string
    {
        return 'type=bind,src='.$sourcePath.',dst='.self::OrbitPath;
    }

    private function nodeVolumeMount(string $nodeContainer, string $suffix, string $target): string
    {
        return "type=volume,src={$nodeContainer}-{$suffix},dst={$target}";
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

    /**
     * @param  list<string>  $roles
     */
    private static function rolesNeedPhpRuntimeImage(array $roles): bool
    {
        return array_intersect($roles, ['dev', 'prod', 'ingress']) !== [];
    }

    private function orbitPathForRole(string $role): string
    {
        return self::OrbitPath;
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
        return array_diff_key($instances, array_flip(['operator', 'gateway', 'dev', 'prod', 'agent', 'ingress']));
    }
}
