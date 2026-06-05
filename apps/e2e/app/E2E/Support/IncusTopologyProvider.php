<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\WireGuard\WireGuardKeyGenerator;

final readonly class IncusTopologyProvider implements E2ETopologyProvider
{
    private const string GatewayWireGuardIp = '10.6.0.2';

    private const string OperatorWireGuardIp = '10.6.0.3';

    private const string DevWireGuardIp = '10.6.0.4';

    private const string ProdWireGuardIp = '10.6.0.5';

    private const string AgentWireGuardIp = '10.6.0.6';

    private const string IngressWireGuardIp = '10.6.0.7';

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

    private static function websocketTopologyKind(E2ETopologyKind $kind): bool
    {
        return in_array($kind, [
            E2ETopologyKind::OperatorGatewayAppdevWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        ], true);
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

        if ($host === null) {
            $resourceLease->release();

            throw new \RuntimeException("Prepared topology {$kind->value} is not available on any Incus host: {$availability['reason']}");
        }

        try {
            if ($options->sourceMountedCheckout) {
                $timer->measure('incus.source-sync', fn (): string => $this->sourceSyncer()->sync($host->config->host, 'incus'));
            }

            $instances = IncusTopologyTemplate::clone($host, $kind, $runId, $timer, sourceMounted: $options->sourceMountedCheckout);

            $sshKeyPair = $this->createSshKeyPair($host, $runId);
            $primaryUsers = $this->prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options, $kind);
            $snapshotReset = $this->prepareSnapshotReset($host, $instances, $primaryUsers, $sshKeyPair, $timer, $options->startGatewayApi, $kind, $options->sourceMountedCheckout);
        } catch (\Throwable $exception) {
            foreach ($instances as $instance) {
                try {
                    $instance->delete();
                } catch (\Throwable) {
                    // Keep the original acquisition failure visible.
                }
            }

            $resourceLease->release();

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $sshKeyPair, $options): array {
            if ($options->sourceMountedCheckout) {
                $cycleTimer->measure('reset.source-sync', fn (): string => $this->sourceSyncer()->sync($host->config->host, 'incus'));
            }

            $newInstances = IncusTopologyTemplate::clone($host, $kind, $runId, $cycleTimer, sourceMounted: $options->sourceMountedCheckout);
            $newPrimaryUsers = $this->prepareInstances($newInstances, $this->config, $sshKeyPair, $cycleTimer, $options, $kind);
            $leaseInstances = $this->leaseInstancesFor($kind, $newInstances);

            return [
                'instances' => $leaseInstances,
                'snapshotReset' => $this->prepareSnapshotReset($host, $newInstances, $newPrimaryUsers, $sshKeyPair, $cycleTimer, $options->startGatewayApi, $kind, $options->sourceMountedCheckout),
            ];
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

        $timer->measure('known-hosts', fn () => $this->clearKnownHosts($instances));
        $timer->measure('wireguard', fn () => $this->retargetRealWireGuard($instances));
        $timer->measure('gateway-ssh-access', fn () => $this->seedGatewaySshAccess($instances));
        $timer->measure('retarget', fn () => $this->retargetTopology($instances, $config, $sshKeyPair, $kind, $options->sourceMountedCheckout));
        $timer->measure('network-ready', fn () => $this->waitForPeerRoutes($instances, $config));

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
        return function (E2EPhaseTimer $cycleTimer) use ($instances, $primaryUsers, $sshKeyPair, $startGatewayApi, $kind, $sourceMountedCheckout): void {
            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
                $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
                $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
            }

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            $cycleTimer->measure('reset.wireguard', fn () => $this->retargetRealWireGuard($instances));
            $cycleTimer->measure('reset.gateway-ssh-access', fn () => $this->seedGatewaySshAccess($instances));
            $cycleTimer->measure('reset.retarget', fn () => $this->retargetTopology($instances, $this->config, $sshKeyPair, $kind, $sourceMountedCheckout));
            $cycleTimer->measure('reset.network-ready', fn () => $this->waitForPeerRoutes($instances, $this->config));

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
        $wgEasy = new E2EWgEasyGateway;
        $wgEasy->start($gateway, $gatewayProviderIp);

        $mesh = $this->meshFor($instances, $gatewayProviderIp);
        $wgEasy->configurePeers($gateway, $mesh->wgEasyPeers());

        foreach (['gateway', 'operator', 'dev', 'prod', 'agent', 'ingress', 'websocket'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $mesh->installRole($instances[$role], $role);
        }

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
     */
    private function meshFor(array $instances, string $gatewayProviderIp): E2EWireGuardMesh
    {
        $generator = app(WireGuardKeyGenerator::class);
        $gatewayHost = $generator->generateKeyPair();
        $operator = $generator->generateKeyPair();
        $dev = isset($instances['dev']) ? $generator->generateKeyPair() : null;
        $prod = isset($instances['prod']) ? $generator->generateKeyPair() : null;
        $agent = isset($instances['agent']) ? $generator->generateKeyPair() : null;
        $ingress = isset($instances['ingress']) ? $generator->generateKeyPair() : null;
        $websocket = isset($instances['websocket']) ? $generator->generateKeyPair() : null;
        $wgEasyPublicKey = trim($instances['gateway']->exec('docker exec wg-easy wg show wg0 public-key')->output());

        return E2EWireGuardMesh::standard(
            gatewayProviderIp: $gatewayProviderIp,
            wgEasyPublicKey: $wgEasyPublicKey,
            gatewayHostPrivateKey: $gatewayHost['private_key'],
            gatewayHostPublicKey: $gatewayHost['public_key'],
            operatorPrivateKey: $operator['private_key'],
            operatorPublicKey: $operator['public_key'],
            devPrivateKey: $dev['private_key'] ?? null,
            devPublicKey: $dev['public_key'] ?? null,
            prodPrivateKey: $prod['private_key'] ?? null,
            prodPublicKey: $prod['public_key'] ?? null,
            agentPrivateKey: $agent['private_key'] ?? null,
            agentPublicKey: $agent['public_key'] ?? null,
            ingressPrivateKey: $ingress['private_key'] ?? null,
            ingressPublicKey: $ingress['public_key'] ?? null,
            websocketPrivateKey: $websocket['private_key'] ?? null,
            websocketPublicKey: $websocket['public_key'] ?? null,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function retargetTopology(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2ETopologyKind $kind, bool $sourceMountedCheckout = false): void
    {
        $operator = $instances['operator'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($operator === null || $gateway === null) {
            return;
        }

        $bootstrapCommand = sprintf(
            'php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway %s --public-host=%s --skip-gateway-service-install',
            escapeshellarg(self::GatewayWireGuardIp),
            escapeshellarg($gateway->waitForIpv4()),
        );

        if ($sourceMountedCheckout) {
            $bootstrapCommand = E2EGatewayApi::sourceMountedGatewayStateCommand().' && '.$bootstrapCommand;
        }

        E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, 'cd /home/orbit/orbit && '.$bootstrapCommand, timeoutSeconds: 120);
        E2EGatewayApi::seedOperatorIdentity($gateway, self::OperatorWireGuardIp, $config->operatorUser);

        $this->retargetOperator($operator, $config, $sshKeyPair, $sourceMountedCheckout);

        if (isset($instances['dev'])) {
            $this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::DevWireGuardIp);

            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit --user=orbit',
                escapeshellarg(self::DevWireGuardIp),
                escapeshellarg(self::DevWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 120);
            $this->seedAppdevDatabaseAndRedis($gateway, $sshKeyPair);
        }

        if (isset($instances['ingress'])) {
            $this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::IngressWireGuardIp);

            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-ingress-node edge-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                escapeshellarg(self::IngressWireGuardIp),
                escapeshellarg(self::IngressWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 120);
        }

        if (isset($instances['prod'])) {
            $this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::ProdWireGuardIp);

            if (E2EPreparedTopology::prodHostsIngressRole($kind) && ! isset($instances['ingress'])) {
                E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                    'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-ingress-node app-prod-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                    escapeshellarg(self::ProdWireGuardIp),
                    escapeshellarg(self::ProdWireGuardIp),
                    escapeshellarg(self::GatewayWireGuardIp),
                ), timeoutSeconds: 120);
            }

            $ingressNode = match (true) {
                isset($instances['ingress']) => 'edge-1',
                E2EPreparedTopology::prodHostsIngressRole($kind) => 'app-prod-1',
                default => null,
            };

            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-app-node app-prod-1 --role=app-prod --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
                escapeshellarg(self::ProdWireGuardIp),
                escapeshellarg(self::ProdWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
                $ingressNode !== null ? ' --ingress-node='.escapeshellarg($ingressNode) : '',
            ), timeoutSeconds: 120);
        }

        if (isset($instances['agent'])) {
            $this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::AgentWireGuardIp);

            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-agent-node agent-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --tld=agent',
                escapeshellarg(self::AgentWireGuardIp),
                escapeshellarg(self::AgentWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 120);
        }

        if (self::websocketTopologyKind($kind) && isset($instances['dev'])) {
            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bake-websocket-node app-dev-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --redis-node=app-dev-1 --converge-runtime',
                escapeshellarg(self::DevWireGuardIp),
                escapeshellarg(self::DevWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 900);
        }

        $this->prunePreparedGatewayRegistry($instances, $sshKeyPair, $kind);
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function prunePreparedGatewayRegistry(array $instances, SshKeyPair $sshKeyPair, E2ETopologyKind $kind): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        $php = E2EPreparedTopology::gatewayRegistryPrunePhp(
            allowedNodeNames: E2EPreparedTopology::gatewayNodeNamesForRoles(array_keys($instances)),
            allowedRolesByNode: E2EPreparedTopology::gatewayAllowedRoleAssignmentsFor($kind, array_keys($instances)),
        );

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $sshKeyPair,
            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 120,
        );
    }

    private function retargetOperator(IncusInstance $operator, E2EConfig $config, SshKeyPair $sshKeyPair, bool $sourceMountedCheckout): void
    {
        $gatewayIpValue = var_export(self::GatewayWireGuardIp, true);

        $php = <<<PHP
\$gateway = \\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'gateway'],
    [
        'tld' => null,
        'platform' => 'unknown',
        'host' => {$gatewayIpValue},
        'wireguard_address' => {$gatewayIpValue},
        'gateway_endpoint' => null,
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ],
);

\\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
    ['node_id' => \$gateway->id, 'role' => 'gateway'],
    ['status' => 'active', 'settings' => [], 'last_error' => null, 'converged_at' => now()],
);

\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->fill([
    'gateway_url' => 'https://'.{$gatewayIpValue},
    'gateway_wg_ip' => {$gatewayIpValue},
]);
\$settings->save();
PHP;

        if (! $sourceMountedCheckout) {
            E2ECommand::ssh(
                $operator,
                $config->operatorUser,
                $sshKeyPair,
                'cd /home/'.$config->operatorUser.'/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
                timeoutSeconds: 120,
            );
        }

        $this->writeOperatorCliConfig($operator, $config, $sshKeyPair, writeSourceEnv: ! $sourceMountedCheckout);
    }

    private function writeOperatorCliConfig(IncusInstance $operator, E2EConfig $config, SshKeyPair $sshKeyPair, bool $writeSourceEnv): void
    {
        $gatewayUrl = 'http://'.self::GatewayWireGuardIp;
        $configDir = "/home/{$config->operatorUser}/.config/orbit";
        $configPath = "{$configDir}/config.json";
        $commands = [];

        if ($writeSourceEnv) {
            $commands = [
                'cd '.escapeshellarg("/home/{$config->operatorUser}/orbit/apps/cli"),
                'touch .env',
                "grep -Ev '^(ORBIT_GATEWAY_URL|ORBIT_GATEWAY_IDENTITY)=' .env > .env.tmp || true",
                'mv .env.tmp .env',
                sprintf("printf 'ORBIT_GATEWAY_URL=%%s\\n' %s >> .env", escapeshellarg($gatewayUrl)),
                sprintf('chown %s:%s .env', escapeshellarg($config->operatorUser), escapeshellarg($config->operatorUser)),
            ];
        }

        $commands = [
            ...$commands,
            sprintf('mkdir -p %s', escapeshellarg($configDir)),
            sprintf('chmod 0700 %s', escapeshellarg($configDir)),
            sprintf('printf %%s %s > %s', escapeshellarg($this->cliJsonConfigBody($gatewayUrl)), escapeshellarg($configPath)),
            sprintf('chmod 0600 %s', escapeshellarg($configPath)),
        ];

        E2ECommand::ssh(
            $operator,
            $config->operatorUser,
            $sshKeyPair,
            implode(' && ', $commands),
            timeoutSeconds: 60,
        );
    }

    private function cliJsonConfigBody(string $gatewayUrl): string
    {
        return json_encode([
            'schema_version' => 1,
            'active_gateway' => 'default',
            'gateways' => [
                'default' => [
                    'url' => $gatewayUrl,
                    'wireguard_ip' => self::GatewayWireGuardIp,
                    'ca_pem_path' => null,
                    'ca_sha256' => null,
                    'ca_fingerprint' => null,
                    'timeout' => 30,
                    'self_mode' => 'wireguard_https',
                ],
            ],
            'defaults' => [
                'node' => null,
                'profile' => null,
            ],
            'meta' => [
                'imported_from' => null,
                'imported_at' => null,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function seedAppdevDatabaseAndRedis(IncusInstance $gateway, SshKeyPair $sshKeyPair): void
    {
        E2ECommand::ssh(
            $gateway,
            'orbit',
            $sshKeyPair,
            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp()),
            timeoutSeconds: 120,
        );
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
     * @param  array<string, IncusInstance>  $instances
     */
    private function seedGatewaySshAccess(array $instances): void
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
        $authorized = [];

        foreach ($targets as $role => $instance) {
            $instanceName = $instance->name();

            if (isset($authorized[$instanceName])) {
                continue;
            }

            $authorized[$instanceName] = true;
            $this->authorizeGatewaySshKey($instance, $publicKey, $role);
        }
    }

    private function gatewayPublicKey(IncusInstance $gateway): string
    {
        $result = E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            'install -d -m 700 ~/.ssh && if ! test -f ~/.ssh/id_ed25519; then ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C orbit-e2e-gateway >/dev/null; fi && chmod 600 ~/.ssh/id_ed25519 && if ! test -f ~/.ssh/id_ed25519.pub; then ssh-keygen -y -f ~/.ssh/id_ed25519 > ~/.ssh/id_ed25519.pub; fi && cat ~/.ssh/id_ed25519.pub',
            timeoutSeconds: 60,
        );

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new \RuntimeException('Could not create Incus gateway SSH key for RemoteShell E2E access.');
        }

        return $publicKey;
    }

    private function authorizeGatewaySshKey(IncusInstance $instance, string $publicKey, string $role): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                <<<'SH'
set -euo pipefail
install -d -m 700 -o orbit -g orbit /home/orbit/.ssh
touch /home/orbit/.ssh/authorized_keys
chown orbit:orbit /home/orbit/.ssh/authorized_keys
chmod 600 /home/orbit/.ssh/authorized_keys
grep -qxF %1$s /home/orbit/.ssh/authorized_keys || printf "%%s\n" %1$s >> /home/orbit/.ssh/authorized_keys

if ! (systemctl restart ssh || systemctl restart sshd || systemctl start ssh || systemctl start sshd); then
    systemctl status ssh sshd --no-pager || true
    exit 1
fi

deadline=$((SECONDS+60))
until ss -ltn | grep -Eq '(^|[[:space:]])LISTEN[[:space:]].*:22[[:space:]]'; do
    if [ "$SECONDS" -ge "$deadline" ]; then
        systemctl status ssh sshd --no-pager || true
        ss -ltn || true
        exit 1
    fi

    sleep 1
done
SH,
                escapeshellarg($publicKey),
            ),
            "Could not authorize gateway SSH key in Incus {$role} instance",
            timeoutSeconds: 60,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function waitForPeerRoutes(array $instances, E2EConfig $config): void
    {
        $gateway = $instances['gateway'] ?? null;
        $operator = $instances['operator'] ?? null;

        if ($gateway === null) {
            return;
        }

        foreach (['dev', 'prod', 'agent', 'ingress'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $wireGuardIp = $this->wireGuardIpForRole($role);

            $this->waitForGatewaySsh($gateway, $wireGuardIp);

            if ($operator !== null) {
                $this->waitForOperatorHostKeyScan($operator, $config, $wireGuardIp);
            }
        }
    }

    /**
     * Clones inherit `~/.ssh/known_hosts` from their templates. Templates pick
     * up stale entries from earlier bake-time SSHes (e.g. operator bootstrapping
     * dev/prod through their provider IPs), and Incus reuses provider IPs
     * across runs, so the clone IPs collide with stale fingerprints and trip
     * StrictHostKeyChecking inside production SSH paths.
     *
     * Wipe per-user known_hosts on every leased clone so the lease starts with
     * an empty trust file. Future SSHes use `StrictHostKeyChecking=accept-new`
     * and repopulate cleanly.
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

    private function waitForGatewaySsh(IncusInstance $gateway, string $wireGuardIp): void
    {
        E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            sprintf(
                <<<'SH'
deadline=$((SECONDS+180))
successes=0

until [ "$successes" -ge 3 ]; do
    if ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR -o ConnectTimeout=10 -o ServerAliveInterval=30 -o ServerAliveCountMax=10 orbit@%s true; then
        successes=$((successes+1))
    else
        successes=0
    fi

    if [ "$successes" -ge 3 ]; then
        exit 0
    fi

    if [ "$SECONDS" -ge "$deadline" ]; then
        exit 1
    fi

    sleep 2
done
SH,
                escapeshellarg($wireGuardIp),
            ),
            timeoutSeconds: 210,
        );
    }

    private function waitForGatewayHostKeyScan(IncusInstance $gateway, SshKeyPair $sshKeyPair, string $wireGuardIp): void
    {
        $this->waitForHostKeyScan($gateway, 'orbit', $sshKeyPair, $wireGuardIp);
    }

    private function waitForOperatorHostKeyScan(IncusInstance $operator, E2EConfig $config, string $wireGuardIp): void
    {
        $this->waitForHostKeyScan($operator, $config->operatorUser, new SshKeyPair('/dev/null', '/dev/null'), $wireGuardIp);
    }

    private function waitForHostKeyScan(IncusInstance $instance, string $user, SshKeyPair $sshKeyPair, string $wireGuardIp): void
    {
        E2ECommand::ssh(
            $instance,
            $user,
            $sshKeyPair,
            sprintf(
                'deadline=$((SECONDS+60)); until ssh-keyscan -T 5 -t ed25519,ecdsa,rsa %1$s >/dev/null 2>&1; do if [ "$SECONDS" -ge "$deadline" ]; then ssh-keyscan -T 10 -t ed25519,ecdsa,rsa %1$s; exit 1; fi; sleep 2; done',
                escapeshellarg($wireGuardIp),
            ),
            timeoutSeconds: 75,
        );
    }

    private function wireGuardIpForRole(string $role): string
    {
        return match ($role) {
            'gateway' => self::GatewayWireGuardIp,
            'operator' => self::OperatorWireGuardIp,
            'dev' => self::DevWireGuardIp,
            'prod' => self::ProdWireGuardIp,
            'agent' => self::AgentWireGuardIp,
            'ingress' => self::IngressWireGuardIp,
            default => throw new \RuntimeException("Unknown topology role [{$role}]."),
        };
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
