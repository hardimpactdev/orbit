<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class IncusTopologyProvider implements E2ETopologyProvider
{
    private const string GatewayWireGuardIp = '10.6.0.2';

    public function __construct(
        private E2EConfig $config,
        private ?SourceMountedCheckoutSyncer $sourceSyncer = null,
    ) {}

    public function name(): string
    {
        return 'incus';
    }

    public function capabilities(): E2ETopologyCapabilities
    {
        return E2ETopologyCapabilities::vm();
    }

    public function availability(E2ETopologyKind $kind): ProviderAvailability
    {
        if (! E2EPreparedTopology::supportsKind($kind)) {
            return ProviderAvailability::unavailable(E2EPreparedTopology::unsupportedKindMessage($kind));
        }

        if (IncusWarmTopologyPool::enabled()) {
            try {
                $hostSlots = IncusWarmTopologyPool::availableHostSlots($this->config, $kind);
            } catch (\InvalidArgumentException $exception) {
                return ProviderAvailability::unavailable($exception->getMessage());
            }

            $host = array_key_first($hostSlots);

            if ($host === null) {
                return ProviderAvailability::unavailable("warm prepared topology {$kind->value} is not available on any Incus host. Run composer e2e:prepare-warm-topology -- --force {$kind->value}.");
            }

            return ProviderAvailability::available("warm prepared topology {$kind->value} is available on {$host}");
        }

        $availability = IncusHostPool::fromEnvironment($this->config)->availabilityFor(
            $kind,
            checkCapacity: false,
        );
        $host = $availability['host'];

        if ($host === null) {
            return ProviderAvailability::unavailable("prepared topology {$kind->value} is not available on any Incus host: {$availability['reason']}");
        }

        $capacityReason = $this->capacityConfigurationUnavailableReason($kind);

        if ($capacityReason !== null) {
            return ProviderAvailability::unavailable($capacityReason);
        }

        return ProviderAvailability::available("prepared topology {$kind->value} is available on {$host->config->host}");
    }

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
    {
        if ($this->shouldAcquireWarmSnapshots($options)) {
            return $this->acquireWarm($kind, $timer, $options);
        }

        $pool = IncusHostPool::fromEnvironment($this->config);
        $resourceLease = $this->acquireResourceLease($kind);
        $availability = $timer->measure('availability', fn () => $pool->availabilityFor($kind, [$resourceLease->host()], checkCapacity: false));
        $host = $availability['host'];
        $instances = [];
        $networkName = 'ob-'.substr(md5($runId), 0, 12);

        $hash = crc32($runId);
        $subnetByte = 10 + ($hash % 190);
        $subnetPrefix = "10.240.{$subnetByte}";

        if ($host === null) {
            $resourceLease->release();

            throw new \RuntimeException("Prepared topology {$kind->value} is not available on any Incus host: {$availability['reason']}");
        }

        try {
            if ($options->sourceMountedCheckout) {
                $timer->measure('incus.source-sync', fn (): string => $this->sourceSyncer()->sync($host->config->host, 'incus'));
            }

            $timer->measure('incus.network.create', function () use ($host, $networkName, $subnetPrefix) {
                $host->run("incus network delete {$networkName} >/dev/null 2>&1 || true");
                $result = $host->run("incus network create {$networkName} ipv4.address={$subnetPrefix}.1/24 ipv4.nat=true ipv6.address=none raw.dnsmasq=port=0");
                if (! $result->successful()) {
                    throw new \RuntimeException("Could not create Incus network {$networkName}: ".$result->errorOutput());
                }
            });

            $instances = IncusTopologyTemplate::clone($host, $kind, $runId, $timer, sourceMounted: $options->sourceMountedCheckout, networkName: $networkName, subnetPrefix: $subnetPrefix);

            $sshKeyPair = $this->createSshKeyPair($host, $runId);
            $primaryUsers = $this->prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options, $kind);
            $snapshotReset = $this->prepareSnapshotReset($host, $instances, $primaryUsers, $sshKeyPair, $timer, $options->startGatewayApi, $kind, $options->sourceMountedCheckout);
        } catch (\Throwable $exception) {
            // Keep instances for debugging
            /*
            foreach ($instances as $instance) {
                try {
                    $instance->delete();
                } catch (\Throwable) {
                    // Keep the original acquisition failure visible.
                }
            }
            */

            /*
            try {
                $host->run("incus network delete {$networkName} >/dev/null 2>&1 || true");
            } catch (\Throwable) {
            }
            */

            $resourceLease->release();

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $sshKeyPair, $options, $networkName, $subnetPrefix): array {
            if ($options->sourceMountedCheckout) {
                $cycleTimer->measure('reset.source-sync', fn (): string => $this->sourceSyncer()->sync($host->config->host, 'incus'));
            }

            $newInstances = IncusTopologyTemplate::clone($host, $kind, $runId, $cycleTimer, sourceMounted: $options->sourceMountedCheckout, networkName: $networkName, subnetPrefix: $subnetPrefix);
            $newPrimaryUsers = $this->prepareInstances($newInstances, $this->config, $sshKeyPair, $cycleTimer, $options, $kind);
            $leaseInstances = $this->leaseInstancesFor($kind, $newInstances);

            return [
                'instances' => $leaseInstances,
                'snapshotReset' => $this->prepareSnapshotReset($host, $newInstances, $newPrimaryUsers, $sshKeyPair, $cycleTimer, $options->startGatewayApi, $kind, $options->sourceMountedCheckout),
            ];
        };

        $teardown = function (E2EPhaseTimer $teardownTimer) use ($host, $networkName): void {
            $teardownTimer->measure('incus.network.delete', fn () => $host->run("incus network delete {$networkName} >/dev/null 2>&1 || true"));
        };

        $leaseInstances = $this->leaseInstancesFor($kind, $instances);

        return new E2ETopologyLease(
            kind: $kind,
            operator: $leaseInstances['operator'],
            gateway: $leaseInstances['gateway'] ?? null,
            dev: $leaseInstances['dev'] ?? null,
            prod: $leaseInstances['prod'] ?? null,
            sshKeyPair: $sshKeyPair,
            rebuild: $rebuild,
            snapshotReset: $snapshotReset,
            teardown: $teardown,
            gatewayApiIp: self::GatewayWireGuardIp,
            resourceLease: $resourceLease,
            agent: $leaseInstances['agent'] ?? null,
            ingress: $leaseInstances['ingress'] ?? null,
            additionalInstances: $this->additionalInstancesFrom($leaseInstances),
        );
    }

    public function prepareWarmSnapshots(E2ETopologyKind $kind, int $slots, E2EPhaseTimer $timer, bool $replaceExisting = false): array
    {
        $host = IncusHostPool::fromEnvironment($this->config)->first();

        if ($host === null) {
            throw new \RuntimeException('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        if (! IncusTopologyTemplate::availableOn($host, $kind)) {
            throw new \RuntimeException("Prepared topology {$kind->value} is not available on {$host->config->host}. Run composer e2e:prepare-topology -- --force {$kind->value} first.");
        }

        $maxSlots = IncusWarmTopologyPool::maxSlotsForHost($this->config, $kind, $host->config->host);

        if ($slots > $maxSlots) {
            throw new \RuntimeException("Warm topology {$kind->value} requested {$slots} slots, but {$host->config->host} can fit {$maxSlots} warm slot(s). Increase ORBIT_E2E_INCUS_HOST_VM_CAPS or lower ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS.");
        }

        $manifest = [];

        for ($slot = 1; $slot <= $slots; $slot++) {
            $runId = IncusWarmTopologyPool::runId($kind, $slot);
            $names = IncusWarmTopologyPool::instanceNames($kind, $slot);

            if ($replaceExisting) {
                $timer->measure("warm.cleanup.slot-{$slot}", fn () => $host->deleteInstancesIfPresent($names));
            }

            $slotAvailable = IncusWarmTopologyPool::slotAvailableOn($host, $kind, $slot);

            if (! $replaceExisting && $slotAvailable) {
                $manifest[] = [
                    'host' => $host->config->host,
                    'slot' => $slot,
                    'run_id' => $runId,
                    'instances' => $names,
                    'snapshot' => IncusWarmTopologyPool::SnapshotName,
                    'reused' => true,
                ];

                continue;
            }

            if (! $replaceExisting && ! $slotAvailable) {
                $timer->measure("warm.cleanup.slot-{$slot}", fn () => $host->deleteInstancesIfPresent($names));
            }

            $instances = $timer->measure(
                "warm.clone.slot-{$slot}",
                fn (): array => IncusTopologyTemplate::clone($host, $kind, $runId, $timer->child("warm.slot-{$slot}"), stateful: true),
            );

            $sshKeyPair = $this->createSshKeyPair($host, $runId);
            $this->prepareInstances(
                $instances,
                $this->config,
                $sshKeyPair,
                $timer->child("warm.slot-{$slot}"),
                new E2ETopologyAcquisitionOptions(
                    sshUsers: ['operator' => $this->config->operatorUser],
                    startGatewayApi: true,
                ),
                $kind,
            );

            foreach ($instances as $role => $instance) {
                $timer->measure("warm.snapshot.slot-{$slot}.{$role}", fn () => $instance->snapshotStatefully(IncusWarmTopologyPool::SnapshotName));
            }

            $timer->measure("warm.stop.slot-{$slot}", fn () => $host->stopInstancesIfRunning($names));

            $manifest[] = [
                'host' => $host->config->host,
                'slot' => $slot,
                'run_id' => $runId,
                'instances' => $names,
                'snapshot' => IncusWarmTopologyPool::SnapshotName,
                'reused' => false,
            ];
        }

        return $manifest;
    }

    private function shouldAcquireWarmSnapshots(E2ETopologyAcquisitionOptions $options): bool
    {
        if (! IncusWarmTopologyPool::enabled()) {
            return false;
        }

        return ! $options->sourceMountedCheckout;
    }

    private function sourceSyncer(): SourceMountedCheckoutSyncer
    {
        return $this->sourceSyncer ?? new SourceMountedCheckoutSyncer;
    }

    private function acquireWarm(E2ETopologyKind $kind, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
    {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));
        $pool = E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $this->config->slotWaitSeconds,
            staleSeconds: $this->config->slotStaleSeconds,
        );
        $hostSlots = IncusWarmTopologyPool::availableHostSlots($this->config, $kind);
        $capacityLease = $pool->acquireWeighted('incus', $this->preparedHostVmCaps($kind), $requiredSlots, $this->config->exclusiveHosts);

        try {
            $host = $capacityLease->host();
            $warmSlots = $hostSlots[$host] ?? 0;

            if ($warmSlots < 1) {
                throw new \RuntimeException("No warm prepared topology {$kind->value} slots are available on {$host}.");
            }

            $warmLease = $pool->acquire(
                IncusWarmTopologyPool::backend($kind),
                [$host => $warmSlots],
            );
        } catch (\Throwable $exception) {
            $capacityLease->release();

            throw $exception;
        }

        $resourceLease = new E2EResourceLeaseSet([
            ...$capacityLease->leases(),
            $warmLease,
        ]);
        $host = new IncusHost($this->config->forHost($warmLease->host()));
        $slot = $warmLease->slot();
        $instances = IncusWarmTopologyPool::instancesFor($host, $kind, $slot);
        $names = IncusWarmTopologyPool::instanceNames($kind, $slot);
        $sshKeyPair = IncusWarmTopologyPool::sshKeyPair($kind, $slot);
        $primaryUsers = $this->sshUsersFor($instances, $this->config, $options);

        try {
            $result = $timer->measure(
                'warm.restore',
                fn () => $host->restoreSnapshotsConcurrently($names, IncusWarmTopologyPool::SnapshotName, stateful: true),
            );

            if (! $result->successful()) {
                throw new \RuntimeException("Could not restore warm topology {$kind->value}: {$result->errorOutput()}");
            }

            $timer->measure('warm.start-if-needed', fn () => $host->startInstancesIfStopped($names));

            foreach ($instances as $role => $instance) {
                $timer->measure("warm.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $timer->measure("warm.command-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }

            if ($options->startGatewayApi && isset($instances['operator'])) {
                $timer->measure('warm.gateway-api.ready', fn () => E2EGatewayApi::waitForGatewayApi(
                    $instances['operator'],
                    $this->config->operatorUser,
                    $sshKeyPair,
                    gatewayIp: self::GatewayWireGuardIp,
                ));
            }
        } catch (\Throwable $exception) {
            $resourceLease->release();

            throw $exception;
        }

        $leaseInstances = $this->leaseInstancesFor($kind, $instances);
        $snapshotReset = $this->warmSnapshotResetFor($host, $instances, $primaryUsers, $sshKeyPair, $options->startGatewayApi);
        $rebuild = fn (E2EPhaseTimer $cycleTimer): array => [
            'instances' => $leaseInstances,
            'snapshotReset' => $snapshotReset,
        ];
        $bulkCleanup = $this->warmSnapshotCleanupFor($host, $instances);

        return new E2ETopologyLease(
            kind: $kind,
            operator: $leaseInstances['operator'],
            gateway: $leaseInstances['gateway'] ?? null,
            dev: $leaseInstances['dev'] ?? null,
            prod: $leaseInstances['prod'] ?? null,
            sshKeyPair: $sshKeyPair,
            rebuild: $rebuild,
            snapshotReset: $snapshotReset,
            bulkCleanup: $bulkCleanup,
            gatewayApiIp: self::GatewayWireGuardIp,
            resourceLease: $resourceLease,
            agent: $leaseInstances['agent'] ?? null,
            ingress: $leaseInstances['ingress'] ?? null,
            additionalInstances: $this->additionalInstancesFrom($leaseInstances),
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function warmSnapshotResetFor(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, bool $startGatewayApi): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($host, $instances, $primaryUsers, $sshKeyPair, $startGatewayApi): void {
            $names = array_map(
                static fn (IncusInstance $instance): string => $instance->name(),
                array_values($instances),
            );

            $result = $cycleTimer->measure(
                'warm.reset.restore-stateful.all',
                fn () => $host->restoreSnapshotsConcurrently($names, IncusWarmTopologyPool::SnapshotName, stateful: true),
            );

            if (! $result->successful()) {
                throw new \RuntimeException("Could not restore warm topology snapshots: {$result->errorOutput()}");
            }

            $cycleTimer->measure('warm.reset.start-if-needed.all', fn () => $host->startInstancesIfStopped($names));

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("warm.reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("warm.reset.command-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }

            if ($startGatewayApi && isset($instances['operator'])) {
                $cycleTimer->measure('warm.reset.gateway-api.ready', fn () => E2EGatewayApi::waitForGatewayApi(
                    $instances['operator'],
                    $this->config->operatorUser,
                    $sshKeyPair,
                    gatewayIp: self::GatewayWireGuardIp,
                ));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function warmSnapshotCleanupFor(IncusHost $host, array $instances): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($host, $instances): void {
            $names = array_map(
                static fn (IncusInstance $instance): string => $instance->name(),
                array_values($instances),
            );

            $cycleTimer->measure('warm.cleanup.stop.all', fn () => $host->stopInstancesIfRunning($names));
        };
    }

    private function acquireResourceLease(E2ETopologyKind $kind): E2EResourceLeaseSet
    {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));

        return E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $this->config->slotWaitSeconds,
            staleSeconds: $this->config->slotStaleSeconds,
        )->acquireWeighted('incus', $this->preparedHostVmCaps($kind), $requiredSlots, $this->config->exclusiveHosts);
    }

    /**
     * @return array<string, int>
     */
    private function preparedHostVmCaps(E2ETopologyKind $kind): array
    {
        $caps = [];

        foreach ($this->config->incusHostCandidates() as $hostName) {
            $host = new IncusHost($this->config->forHost($hostName));

            if (! IncusTopologyTemplate::availableOn($host, $kind)) {
                continue;
            }

            $caps[$hostName] = $this->config->incusMaxVmsForHost($hostName);
        }

        return $caps;
    }

    private function capacityConfigurationUnavailableReason(E2ETopologyKind $kind): ?string
    {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));
        $reasons = [];
        $hasPreparedHost = false;

        foreach ($this->config->incusHostCandidates() as $hostName) {
            $host = new IncusHost($this->config->forHost($hostName));

            if (! IncusTopologyTemplate::availableOn($host, $kind)) {
                continue;
            }

            $hasPreparedHost = true;

            try {
                $hostCap = $this->config->incusMaxVmsForHost($hostName);
            } catch (\InvalidArgumentException $exception) {
                $reasons[] = "{$hostName}: {$exception->getMessage()}";

                continue;
            }

            if ($hostCap >= $requiredSlots) {
                return null;
            }

            $reasons[] = "{$hostName} allows {$hostCap}/{$requiredSlots} VMs";
        }

        if (! $hasPreparedHost) {
            return null;
        }

        return "Incus VM capacity cannot fit prepared topology {$kind->value}: ".implode('; ', $reasons);
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function prepareInstances(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options, E2ETopologyKind $kind): array
    {
        $sshUsers = $this->sshUsersFor($instances, $config, $options);
        $primaryUsers = [];

        foreach ($sshUsers as $role => $primaryUser) {
            $instance = $instances[$role] ?? null;

            if ($instance === null) {
                continue;
            }

            $primaryUsers[$role] = $primaryUser;

            $timer->measure("command-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
        }

        if (isset($instances['gateway'])) {
            $timer->measure('gateway-db-node-cleanup', function () use ($instances) {
                $keepNodeNames = ['gateway'];
                foreach (array_keys($instances) as $role) {
                    $name = match ($role) {
                        'gateway' => 'gateway',
                        'operator' => 'operator-1',
                        'dev' => 'app-dev-1',
                        'prod' => 'app-prod-1',
                        'agent' => 'agent-1',
                        default => null,
                    };
                    if ($name !== null) {
                        $keepNodeNames[] = $name;
                    }
                }
                $placeholders = implode(',', array_map(fn ($n) => "'{$n}'", array_unique($keepNodeNames)));
                $php = sprintf(
                    "\$db = new PDO('sqlite:/home/orbit/.config/orbit/gateway.sqlite'); ".
                    '$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); '.
                    '$db->exec("DELETE FROM nodes WHERE name NOT IN (%s)"); '.
                    '$db->exec("DELETE FROM node_role WHERE node_id NOT IN (SELECT id FROM nodes)");',
                    $placeholders
                );
                $res = $instances['gateway']->exec(sprintf('php -r %s', escapeshellarg($php)), timeoutSeconds: 30);
                if (! $res->successful()) {
                    throw new \RuntimeException('Gateway DB node cleanup failed: '.$res->errorOutput().' '.$res->output());
                }
            });
        }

        $timer->measure('known-hosts', fn () => $this->clearKnownHosts($instances));
        $timer->measure('wireguard', fn () => $this->retargetRealWireGuard($instances));

        if ($options->startGatewayApi && isset($instances['gateway'])) {
            $timer->measure('gateway-api.start', fn () => E2EGatewayApi::start(
                $instances['gateway'],
                'topology-lease',
                gatewayIp: self::GatewayWireGuardIp,
            ));
        }

        return $primaryUsers;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function sshUsersFor(array $instances, E2EConfig $config, E2ETopologyAcquisitionOptions $options): array
    {
        if ($options->sshUsers !== null) {
            return $options->sshUsers;
        }

        $users = [];

        foreach (array_keys($instances) as $role) {
            $users[$role] = match ($role) {
                'operator' => $config->operatorUser,
                default => 'orbit',
            };
        }

        return $users;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function prepareSnapshotReset(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, bool $startGatewayApi, E2ETopologyKind $kind, bool $sourceMountedCheckout): ?\Closure
    {
        if (! $this->shouldPrepareSnapshotReset()) {
            return null;
        }

        $resetMode = $this->resetMode();

        foreach ($instances as $role => $instance) {
            if ($resetMode === 'stateful-restore') {
                $timer->measure("snapshot-stateful.{$role}", fn () => $instance->snapshotStatefully('lease-warm'));

                continue;
            }

            $timer->measure("snapshot.{$role}", fn () => $instance->snapshot('lease-clean'));
        }

        return $resetMode === 'stateful-restore'
            ? $this->statefulResetFor($host, $instances, $primaryUsers, $sshKeyPair)
            : $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair, $startGatewayApi, $kind, $sourceMountedCheckout);
    }

    private function shouldPrepareSnapshotReset(): bool
    {
        return in_array($this->resetMode(), ['snapshot-restore', 'stateful-restore'], true);
    }

    private function resetMode(): string
    {
        $resetMode = getenv('ORBIT_E2E_TOPOLOGY_RESET');

        return is_string($resetMode) && $resetMode !== '' ? $resetMode : 'fresh-clone';
    }

    private function snapshotResetFor(array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, bool $startGatewayApi, E2ETopologyKind $kind, bool $sourceMountedCheckout): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($instances, $primaryUsers, $sshKeyPair, $startGatewayApi): void {
            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
                $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
                $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
            }

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            $cycleTimer->measure('reset.wireguard', fn () => $this->retargetRealWireGuard($instances));

            if ($startGatewayApi && isset($instances['gateway'])) {
                $cycleTimer->measure('reset.gateway-api.start', fn () => E2EGatewayApi::start(
                    $instances['gateway'],
                    'topology-reset',
                    gatewayIp: self::GatewayWireGuardIp,
                ));
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("reset.ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function statefulResetFor(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($host, $instances, $primaryUsers, $sshKeyPair): void {
            $result = $cycleTimer->measure(
                'reset.restore-stateful.all',
                fn () => $host->restoreSnapshotsConcurrently(
                    array_map(
                        fn (IncusInstance $instance): string => $instance->name(),
                        array_values($instances),
                    ),
                    'lease-warm',
                    stateful: true,
                ),
            );

            if (! $result->successful()) {
                throw new \RuntimeException("Could not restore stateful topology snapshots: {$result->errorOutput()}");
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("reset.command-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function retargetRealWireGuard(array $instances): void
    {
        $operator = $instances['operator'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($operator === null || $gateway === null) {
            return;
        }

        $gatewayProviderIp = $gateway->waitForIpv4();

        // Ensure wg-easy container and its wg0 interface are up
        $gateway->exec(
            'for i in $(seq 1 30); do '.
            '  docker exec wg-easy ip link show wg0 >/dev/null 2>&1 && break; '.
            '  sleep 1; '.
            'done; '.
            'docker exec wg-easy ip addr replace 10.6.0.1/24 dev wg0 >/dev/null 2>&1 || true; '.
            'docker exec wg-easy ip route '.'replace 10.6.0.0/24 dev wg0 >/dev/null 2>&1 || true'
        );

        $wgEasyPublicKey = E2EWireGuardMesh::FIXED_KEYS['wg-easy']['public_key'];
        $endpoint = "{$gatewayProviderIp}:51820";

        foreach (['gateway', 'operator', 'dev', 'prod', 'agent', 'ingress', 'websocket'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $instances[$role]->exec(sprintf(
                'sudo wg set wg-orbit peer %s endpoint %s',
                escapeshellarg($wgEasyPublicKey),
                escapeshellarg($endpoint)
            ), timeoutSeconds: 30);
        }

        $mesh = E2EWireGuardMesh::fixed($gatewayProviderIp);
        $mesh->verifyRole($gateway, 'gateway', array_values(array_filter([
            'operator',
            isset($instances['dev']) ? 'dev' : null,
            isset($instances['prod']) ? 'prod' : null,
            isset($instances['agent']) ? 'agent' : null,
            isset($instances['ingress']) ? 'ingress' : null,
            isset($instances['websocket']) ? 'websocket' : null,
        ])));
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, IncusInstance>
     */
    private function leaseInstancesFor(E2ETopologyKind $kind, array $instances): array
    {
        if (E2EPreparedTopology::prodHostsIngressRole($kind) && isset($instances['prod']) && ! isset($instances['ingress'])) {
            $instances['ingress'] = $instances['prod'];
        }

        return $instances;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, IncusInstance>
     */
    private function additionalInstancesFrom(array $instances): array
    {
        return array_diff_key($instances, array_flip(['operator', 'gateway', 'dev', 'prod', 'agent', 'ingress']));
    }

    /**
     * Wipe per-user known_hosts on every leased clone so the lease starts with
     * an empty trust file.
     *
     * @param  array<string, IncusInstance>  $instances
     */
    private function clearKnownHosts(array $instances): void
    {
        foreach ($instances as $instance) {
            $instance->exec(
                'for d in /root /home/*; do '
                    .'[ -d "$d/.ssh" ] || continue; '
                    .'rm -f "$d/.ssh/known_hosts" "$d/.ssh/known_hosts.old"; '
                .'done',
                timeoutSeconds: 30,
            );
        }
    }

    private function createSshKeyPair(IncusHost $host, string $runId): SshKeyPair
    {
        $workDirectory = "/tmp/orbit-e2e-topology-{$runId}";

        $result = $host->run(sprintf(
            'rm -rf %s && mkdir -p %s',
            escapeshellarg($workDirectory),
            escapeshellarg($workDirectory),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not create topology work directory: {$result->errorOutput()}");
        }

        $privateKeyPath = "{$workDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";

        $result = $host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$runId}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not create E2E SSH key pair: {$result->errorOutput()}");
        }

        return new SshKeyPair($privateKeyPath, $publicKeyPath);
    }
}
