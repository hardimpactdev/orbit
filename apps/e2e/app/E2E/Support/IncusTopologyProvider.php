<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class IncusTopologyProvider implements E2ETopologyProvider
{
    private const string GatewayWireGuardIp = '10.6.0.2';

    private const string OperatorWireGuardIp = '10.6.0.3';

    private const string DevWireGuardIp = '10.6.0.4';

    private const string ProdWireGuardIp = '10.6.0.5';

    private const string AgentWireGuardIp = '10.6.0.6';

    private const string IngressWireGuardIp = '10.6.0.7';

    private const string GatewayConfigRoot = '/home/orbit/.config/orbit';

    private const string GatewayDatabase = self::GatewayConfigRoot.'/gateway.sqlite';

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
        return in_array(
            $kind,
            [
                E2ETopologyKind::OperatorGatewayAppdevWebsocket,
                E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket,
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            ],
            true,
        );
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
                return ProviderAvailability::unavailable(
                    "warm prepared topology {$kind->value} is not available on any Incus host. Run composer e2e:prepare-warm-topology -- --force {$kind->value}.",
                );
            }

            return ProviderAvailability::available("warm prepared topology {$kind->value} is available on {$host}");
        }

        $availability = IncusHostPool::fromEnvironment($this->config)->availabilityFor($kind, checkCapacity: false);
        $host = $availability['host'];

        if ($host === null) {
            return ProviderAvailability::unavailable(
                "prepared topology {$kind->value} is not available on any Incus host: {$availability['reason']}",
            );
        }

        $capacityReason = $this->capacityConfigurationUnavailableReason($kind);

        if ($capacityReason !== null) {
            return ProviderAvailability::unavailable($capacityReason);
        }

        return ProviderAvailability::available(
            "prepared topology {$kind->value} is available on {$host->config->host}",
        );
    }

    public function acquire(
        E2ETopologyKind $kind,
        string $runId,
        E2EPhaseTimer $timer,
        E2ETopologyAcquisitionOptions $options,
    ): E2ETopologyLease {
        if ($this->shouldAcquireWarmSnapshots($options)) {
            return $this->acquireWarm($kind, $timer, $options);
        }

        $resourceLease = $this->acquireResourceLease($kind);
        $host = new IncusHost($this->config->forHost($resourceLease->host()));
        $instances = [];
        $sourcePath = null;

        try {
            $workerNetwork = IncusWorkerNetwork::forSlot($this->config, $resourceLease->slot());
            $timer->measure('incus.worker-network', fn () => $workerNetwork->ensureOn($host));

            $sourcePath = $options->sourceMountedCheckout
                ? $this->sourceSyncer()->sourcePath($host->config->host, 'incus', $runId)
                : null;
            $sourcePath = $this->syncSourcePath($host, $runId, $timer, $options->sourceMountedCheckout, $sourcePath);

            $instances = IncusTopologyTemplate::clone(
                $host,
                $kind,
                $runId,
                $timer,
                sourceMounted: $options->sourceMountedCheckout,
                network: $workerNetwork,
                sourcePath: $options->sourceMountedCheckout ? $sourcePath : null,
            );

            $sshKeyPair = $this->createSshKeyPair($host, $runId);
            $primaryUsers = $this->prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options, $kind);
            $snapshotReset = $this->prepareSnapshotReset(
                $host,
                $instances,
                $primaryUsers,
                $sshKeyPair,
                $timer,
                $options->startGatewayApi,
                $kind,
                $options->sourceMountedCheckout,
            );
        } catch (\Throwable $exception) {
            $exception = $this->acquisitionFailureAfterCleanup(
                $exception,
                $host,
                $this->cloneNames($kind, $runId),
                $options->sourceMountedCheckout && is_string($sourcePath) ? $sourcePath : null,
            );

            $resourceLease->release();

            throw $exception;
        }

        $rebuild = function (E2EPhaseTimer $cycleTimer) use (
            $host,
            $kind,
            $runId,
            $sshKeyPair,
            $options,
            $workerNetwork,
        ): array {
            $cycleTimer->measure('reset.worker-network', fn () => $workerNetwork->ensureOn($host));

            $sourcePath = self::sourcePathResult($cycleTimer->measure('reset.source-sync', fn (): string => $this->sourceSyncer()->sync(
                $host->config->host,
                'incus',
                scope: $options->sourceMountedCheckout ? $runId : null,
            )));

            $newInstances = IncusTopologyTemplate::clone(
                $host,
                $kind,
                $runId,
                $cycleTimer,
                sourceMounted: $options->sourceMountedCheckout,
                network: $workerNetwork,
                sourcePath: $options->sourceMountedCheckout ? $sourcePath : null,
            );
            $newPrimaryUsers = $this->prepareInstances(
                $newInstances,
                $this->config,
                $sshKeyPair,
                $cycleTimer,
                $options,
                $kind,
            );
            $leaseInstances = $this->leaseInstancesFor($kind, $newInstances);

            return [
                'instances' => $leaseInstances,
                'snapshotReset' => $this->prepareSnapshotReset(
                    $host,
                    $newInstances,
                    $newPrimaryUsers,
                    $sshKeyPair,
                    $cycleTimer,
                    $options->startGatewayApi,
                    $kind,
                    $options->sourceMountedCheckout,
                ),
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
            bulkCleanup: $this->bulkCleanupFor($host, $instances, $options->sourceMountedCheckout ? $sourcePath : null),
            gatewayApiIp: self::GatewayWireGuardIp,
            resourceLease: $resourceLease,
            agent: $leaseInstances['agent'] ?? null,
            ingress: $leaseInstances['ingress'] ?? null,
            additionalInstances: $this->additionalInstancesFrom($leaseInstances),
        );
    }

    /**
     * Delete every acquisition clone in one bulk host call instead of serial
     * per-role deletes. Rebuilt leases reuse the same clone names, so the
     * captured names stay valid across resets.
     *
     * @param  array<string, IncusInstance>  $instances
     */
    private function bulkCleanupFor(IncusHost $host, array $instances, ?string $sourcePath = null): \Closure
    {
        $names = array_values(array_unique(array_map(
            static fn (IncusInstance $instance): string => $instance->name(),
            array_values($instances),
        )));

        return function (E2EPhaseTimer $cycleTimer) use ($host, $names, $sourcePath): void {
            $cleanup = function (?SourceMountedCheckoutMutationFence $mutationFence) use (
                $cycleTimer,
                $host,
                $names,
                $sourcePath,
            ): void {
                $cycleTimer->measure('cleanup.bulk', fn () => $this->deleteInstancesOrFail($host, $names));

                if ($sourcePath !== null) {
                    if ($mutationFence === null) {
                        throw new \LogicException('Scoped source cleanup requires an active mutation generation.');
                    }

                    $cycleTimer->measure('cleanup.source', fn () => $this->removeScopedSourcePath(
                        $host,
                        $sourcePath,
                        $mutationFence,
                    ));
                }
            };

            $this->withScopedSourceLock($host, $sourcePath, $cleanup);
        };
    }

    /** @param list<string> $names */
    private function deleteInstancesOrFail(IncusHost $host, array $names): void
    {
        $result = $host->deleteInstancesIfPresent($names);

        if (! $result->successful()) {
            $error = trim($result->errorOutput());

            throw new \RuntimeException(
                'Could not verify cleanup of Incus instances'.($error !== '' ? ": {$error}" : '.'),
            );
        }
    }

    /** @param list<string> $expectedNames */
    private function acquisitionFailureAfterCleanup(
        \Throwable $exception,
        IncusHost $host,
        array $expectedNames,
        ?string $sourcePath,
    ): \Throwable {
        try {
            $cleanup = function (?SourceMountedCheckoutMutationFence $mutationFence) use (
                $host,
                $expectedNames,
                $sourcePath,
            ): void {
                $this->deleteInstancesOrFail($host, $expectedNames);

                if ($sourcePath !== null) {
                    if ($mutationFence === null) {
                        throw new \LogicException('Scoped source cleanup requires an active mutation generation.');
                    }

                    $this->removeScopedSourcePath($host, $sourcePath, $mutationFence);
                }
            };

            $this->withScopedSourceLock($host, $sourcePath, $cleanup);

            return $exception;
        } catch (\Throwable $cleanupException) {
            return new \RuntimeException(
                $exception->getMessage().' Acquisition cleanup also failed: '.$cleanupException->getMessage(),
                previous: $exception,
            );
        }
    }

    /** @return list<string> */
    private function cloneNames(E2ETopologyKind $kind, string $runId): array
    {
        return array_map(static fn (string $role): string => IncusTopologyTemplate::cloneName(
            $runId,
            $role,
        ), IncusTopologyTemplate::rolesFor($kind));
    }

    /**
     * @template TResult
     * @param  \Closure(?SourceMountedCheckoutMutationFence): TResult  $operation
     * @return TResult
     */
    private function withScopedSourceLock(IncusHost $host, ?string $sourcePath, \Closure $operation): mixed
    {
        if ($sourcePath === null || basename(dirname(rtrim(string: $sourcePath, characters: '/'))) !== 'retained') {
            return $operation(null);
        }

        return new SourceMountedCheckoutLifecycleLock($host->config->host, $sourcePath)->run($operation);
    }

    private function syncSourcePath(
        IncusHost $host,
        string $runId,
        E2EPhaseTimer $timer,
        bool $sourceMounted,
        ?string $expectedPath,
    ): string {
        $syncedPath = self::sourcePathResult($timer->measure('incus.source-sync', fn (): string => $this->sourceSyncer()->sync(
            $host->config->host,
            'incus',
            scope: $sourceMounted ? $runId : null,
        )));

        if ($expectedPath !== null && ! hash_equals($expectedPath, $syncedPath)) {
            throw new \RuntimeException(
                "Source sync returned [{$syncedPath}] instead of expected path [{$expectedPath}].",
            );
        }

        return $syncedPath;
    }

    private static function sourcePathResult(mixed $result): string
    {
        if (! is_string($result)) {
            throw new \LogicException('The source sync operation must return its source path.');
        }

        return $result;
    }

    private function removeScopedSourcePath(
        IncusHost $host,
        string $sourcePath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): void {
        if (basename(dirname($sourcePath)) !== 'retained') {
            return;
        }

        $result = $host->run(
            $mutationFence->guardedScript(SourceMountedCheckoutMutationFence::protectedSourceCleanupScript(
                $sourcePath,
            )),
            timeoutSeconds: 120,
        );

        if (! $result->successful()) {
            throw new \RuntimeException(
                "Could not remove scoped Incus source path [{$sourcePath}]: {$result->errorOutput()}",
            );
        }
    }

    public function prepareWarmSnapshots(
        E2ETopologyKind $kind,
        int $slots,
        E2EPhaseTimer $timer,
        bool $replaceExisting = false,
    ): array {
        $host = IncusHostPool::fromEnvironment($this->config)->first();

        if ($host === null) {
            throw new \RuntimeException('No Incus hosts configured. Set ORBIT_E2E_INCUS_HOSTS or ORBIT_E2E_HOST.');
        }

        if (! IncusTopologyTemplate::availableOn($host, $kind)) {
            throw new \RuntimeException(
                "Prepared topology {$kind->value} is not available on {$host->config->host}. Run composer e2e:prepare-topology -- --force {$kind->value} first.",
            );
        }

        $maxSlots = IncusWarmTopologyPool::maxSlotsForHost($this->config, $kind, $host->config->host);

        if ($slots > $maxSlots) {
            throw new \RuntimeException(
                "Warm topology {$kind->value} requested {$slots} slots, but {$host->config->host} can fit {$maxSlots} warm slot(s). Increase ORBIT_E2E_INCUS_HOST_VM_CAPS or lower ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS.",
            );
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

            $workerNetwork = IncusWorkerNetwork::forSlot($this->config, $slot);
            $timer->measure("warm.network.slot-{$slot}", fn () => $workerNetwork->ensureOn($host));

            $instances = $timer->measure("warm.clone.slot-{$slot}", fn (): array => IncusTopologyTemplate::clone(
                $host,
                $kind,
                $runId,
                $timer->child("warm.slot-{$slot}"),
                stateful: true,
                network: $workerNetwork,
            ));

            $sshKeyPair = $this->createSshKeyPair($host, $runId);
            $this->prepareInstances(
                $instances,
                $this->config,
                $sshKeyPair,
                $timer->child("warm.slot-{$slot}"),
                new E2ETopologyAcquisitionOptions(sshUsers: [
                    'operator' => $this->config->operatorUser,
                ], startGatewayApi: true),
                $kind,
            );

            foreach ($instances as $role => $instance) {
                $timer->measure(
                    "warm.snapshot.slot-{$slot}.{$role}",
                    fn () => $instance->snapshotStatefully(IncusWarmTopologyPool::SnapshotName),
                );
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

    private function acquireWarm(
        E2ETopologyKind $kind,
        E2EPhaseTimer $timer,
        E2ETopologyAcquisitionOptions $options,
    ): E2ETopologyLease {
        $requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));
        $pool = E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $this->config->slotWaitSeconds,
            staleSeconds: $this->config->slotStaleSeconds,
        );
        $hostSlots = IncusWarmTopologyPool::availableHostSlots($this->config, $kind);
        $capacityLease = $pool->acquireWeighted(
            'incus',
            $this->preparedHostVmCaps($kind),
            $requiredSlots,
            $this->config->exclusiveHosts,
        );

        try {
            $host = $capacityLease->host();
            $warmSlots = $hostSlots[$host] ?? 0;

            if ($warmSlots < 1) {
                throw new \RuntimeException("No warm prepared topology {$kind->value} slots are available on {$host}.");
            }

            $warmLease = $pool->acquire(IncusWarmTopologyPool::backend($kind), [$host => $warmSlots]);
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
        $workerNetwork = IncusWorkerNetwork::forSlot($this->config, $slot);
        $instances = IncusWarmTopologyPool::instancesFor($host, $kind, $slot);
        $names = IncusWarmTopologyPool::instanceNames($kind, $slot);
        $sshKeyPair = IncusWarmTopologyPool::sshKeyPair($kind, $slot);
        $primaryUsers = $this->sshUsersFor($instances, $this->config, $options);

        try {
            $timer->measure('warm.network', fn () => $workerNetwork->ensureOn($host));

            $result = $timer->measure('warm.restore', fn () => $host->restoreSnapshotsConcurrently(
                $names,
                IncusWarmTopologyPool::SnapshotName,
                stateful: true,
            ));

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

                $timer->measure("warm.command-ready.{$role}", fn () => $instance->waitForSsh(
                    $primaryUser,
                    $sshKeyPair,
                ));
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
        $snapshotReset = $this->warmSnapshotResetFor(
            $host,
            $instances,
            $primaryUsers,
            $sshKeyPair,
            $options->startGatewayApi,
        );
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
    private function warmSnapshotResetFor(
        IncusHost $host,
        array $instances,
        array $primaryUsers,
        SshKeyPair $sshKeyPair,
        bool $startGatewayApi,
    ): \Closure {
        return function (E2EPhaseTimer $cycleTimer) use (
            $host,
            $instances,
            $primaryUsers,
            $sshKeyPair,
            $startGatewayApi,
        ): void {
            $names = array_map(
                static fn (IncusInstance $instance): string => $instance->name(),
                array_values($instances),
            );

            $result = $cycleTimer->measure('warm.reset.restore-stateful.all', fn () => $host->restoreSnapshotsConcurrently(
                $names,
                IncusWarmTopologyPool::SnapshotName,
                stateful: true,
            ));

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

                $cycleTimer->measure("warm.reset.command-ready.{$role}", fn () => $instance->waitForSsh(
                    $primaryUser,
                    $sshKeyPair,
                ));
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
    private function prepareInstances(
        array $instances,
        E2EConfig $config,
        SshKeyPair $sshKeyPair,
        E2EPhaseTimer $timer,
        E2ETopologyAcquisitionOptions $options,
        E2ETopologyKind $kind,
    ): array {
        $sshUsers = $this->sshUsersFor($instances, $config, $options);
        $primaryUsers = [];

        foreach ($sshUsers as $role => $primaryUser) {
            if (isset($instances[$role])) {
                $primaryUsers[$role] = $primaryUser;
            }
        }

        $timer->measure('command-ready', fn () => $this->awaitCommandReady($instances, $primaryUsers, $timer));
        $timer->measure('known-hosts', fn () => $this->clearKnownHosts($instances));

        if ($options->sourceMountedCheckout) {
            $timer->measure('source-mounted-launchers', fn () => $this->activateSourceMountedLaunchers(
                $instances,
                $config,
                $timer,
            ));
        }

        $timer->measure('wireguard', fn () => $this->retargetRealWireGuard($instances, $timer));
        $timer->measure('gateway-ssh-access', fn () => $this->seedGatewaySshAccess($instances, $timer));
        $startGatewayApiBeforeBake = $options->startGatewayApi && isset($instances['gateway'])
            ? fn () => $timer->measure('gateway-api.start', fn () => E2EGatewayApi::start(
                $instances['gateway'],
                'topology-lease',
                gatewayIp: self::GatewayWireGuardIp,
            ))
            : null;
        $timer->measure('retarget', fn () => $this->retargetTopology(
            $instances,
            $config,
            $sshKeyPair,
            $kind,
            $options->sourceMountedCheckout,
            $timer,
            $startGatewayApiBeforeBake,
        ));

        // After retarget writes the agent CLI config, prepare the managed agent
        // user + ACL and probe LocalAgentRuntimeProbe paths.
        if (isset($instances['agent'])) {
            $timer->measure('agent-runtime-readiness', fn () => $this->ensureAgentRuntimeReadiness(
                $instances,
                $timer,
            ));
        }

        $timer->measure('network-ready', fn () => $this->waitForPeerRoutes($instances, $config, $timer));

        return $primaryUsers;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function hostFor(array $instances): IncusHost
    {
        $instance = array_first($instances) ?? throw new \RuntimeException(
            'No Incus instances available to resolve a host from.',
        );

        return $instance->host();
    }

    /**
     * Wait for the command transport of every role concurrently in one host
     * call instead of serial per-role polling.
     *
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function awaitCommandReady(array $instances, array $primaryUsers, E2EPhaseTimer $timer): void
    {
        $tasks = [];

        foreach ($primaryUsers as $role => $primaryUser) {
            $instance = $instances[$role] ?? null;

            if ($instance === null) {
                continue;
            }

            $probe = sprintf(
                'incus exec %s -- runuser -u %s -- bash -lc %s',
                escapeshellarg($instance->name()),
                escapeshellarg($primaryUser),
                escapeshellarg('test "$(uname -s)" = Linux && test -r /etc/os-release'),
            );

            $tasks[$role] = implode("\n", [
                'deadline=$((SECONDS + '.$this->config->timeoutSeconds.'))',
                "until {$probe} >/dev/null 2>&1; do",
                '    if [ "$SECONDS" -ge "$deadline" ]; then echo "Incus command transport is not ready for '
                    .$primaryUser
                    .'@'
                    .$instance->name()
                    .'." >&2; exit 1; fi',
                '    sleep 1',
                'done',
            ]);
        }

        if ($tasks === []) {
            return;
        }

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            $tasks,
            $timer,
            'command-ready',
            timeoutSeconds: $this->config->timeoutSeconds + 60,
            failureMessage: 'Incus command transport never became ready',
        );
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
    private function prepareSnapshotReset(
        IncusHost $host,
        array $instances,
        array $primaryUsers,
        SshKeyPair $sshKeyPair,
        E2EPhaseTimer $timer,
        bool $startGatewayApi,
        E2ETopologyKind $kind,
        bool $sourceMountedCheckout,
    ): ?\Closure {
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
            : $this->snapshotResetFor(
                $instances,
                $primaryUsers,
                $sshKeyPair,
                $startGatewayApi,
                $kind,
                $sourceMountedCheckout,
            );
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

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function snapshotResetFor(
        array $instances,
        array $primaryUsers,
        SshKeyPair $sshKeyPair,
        bool $startGatewayApi,
        E2ETopologyKind $kind,
        bool $sourceMountedCheckout,
    ): \Closure {
        return function (E2EPhaseTimer $cycleTimer) use (
            $instances,
            $primaryUsers,
            $sshKeyPair,
            $startGatewayApi,
            $kind,
            $sourceMountedCheckout,
        ): void {
            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
                $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
                $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
            }

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            if ($sourceMountedCheckout) {
                $cycleTimer->measure('reset.source-mounted-launchers', fn () => $this->activateSourceMountedLaunchers(
                    $instances,
                    $this->config,
                    $cycleTimer,
                ));
            }

            $cycleTimer->measure('reset.wireguard', fn () => $this->retargetRealWireGuard($instances, $cycleTimer));
            $cycleTimer->measure('reset.gateway-ssh-access', fn () => $this->seedGatewaySshAccess(
                $instances,
                $cycleTimer,
            ));
            $startGatewayApiBeforeBake = $startGatewayApi && isset($instances['gateway'])
                ? fn () => $cycleTimer->measure('reset.gateway-api.start', fn () => E2EGatewayApi::start(
                    $instances['gateway'],
                    'topology-reset',
                    gatewayIp: self::GatewayWireGuardIp,
                ))
                : null;
            $cycleTimer->measure('reset.retarget', fn () => $this->retargetTopology(
                $instances,
                $this->config,
                $sshKeyPair,
                $kind,
                $sourceMountedCheckout,
                $cycleTimer,
                $startGatewayApiBeforeBake,
            ));

            if (isset($instances['agent'])) {
                $cycleTimer->measure('reset.agent-runtime-readiness', fn () => $this->ensureAgentRuntimeReadiness(
                    $instances,
                    $cycleTimer,
                ));
            }

            $cycleTimer->measure('reset.network-ready', fn () => $this->waitForPeerRoutes(
                $instances,
                $this->config,
                $cycleTimer,
            ));

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("reset.ssh-ready.{$role}", fn () => $instance->waitForSsh(
                    $primaryUser,
                    $sshKeyPair,
                ));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function statefulResetFor(
        IncusHost $host,
        array $instances,
        array $primaryUsers,
        SshKeyPair $sshKeyPair,
    ): \Closure {
        return function (E2EPhaseTimer $cycleTimer) use ($host, $instances, $primaryUsers, $sshKeyPair): void {
            $result = $cycleTimer->measure('reset.restore-stateful.all', fn () => $host->restoreSnapshotsConcurrently(
                array_map(fn (IncusInstance $instance): string => $instance->name(), array_values($instances)),
                'lease-warm',
                stateful: true,
            ));

            if (! $result->successful()) {
                throw new \RuntimeException("Could not restore stateful topology snapshots: {$result->errorOutput()}");
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("reset.command-ready.{$role}", static fn () => $instance->waitForSsh(
                    $primaryUser,
                    $sshKeyPair,
                ));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function retargetRealWireGuard(array $instances, ?E2EPhaseTimer $timer = null): void
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

        $tasks = [];

        foreach (['gateway', 'operator', 'dev', 'prod', 'agent', 'ingress', 'websocket'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $tasks[$role] = sprintf(
                'incus exec %s -- sh -lc %s',
                escapeshellarg($instances[$role]->name()),
                escapeshellarg($mesh->installScript($role)),
            );
        }

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            $tasks,
            $timer ?? new E2EPhaseTimer,
            'wireguard.install',
            timeoutSeconds: 240,
            failureMessage: 'Could not install wg-orbit on prepared clones',
        );

        $mesh->verifyRole(
            $gateway,
            'gateway',
            array_values(array_filter([
                'operator',
                isset($instances['dev']) ? 'dev' : null,
                isset($instances['prod']) ? 'prod' : null,
                isset($instances['agent']) ? 'agent' : null,
                isset($instances['ingress']) ? 'ingress' : null,
                isset($instances['websocket']) ? 'websocket' : null,
            ])),
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function meshFor(array $instances, string $gatewayProviderIp): E2EWireGuardMesh
    {
        $gatewayHost = E2EWireGuardIdentitySet::forRole('gateway');
        $operator = E2EWireGuardIdentitySet::forRole('operator');
        $dev = isset($instances['dev']) ? E2EWireGuardIdentitySet::forRole('dev') : null;
        $prod = isset($instances['prod']) ? E2EWireGuardIdentitySet::forRole('prod') : null;
        $agent = isset($instances['agent']) ? E2EWireGuardIdentitySet::forRole('agent') : null;
        $ingress = isset($instances['ingress']) ? E2EWireGuardIdentitySet::forRole('ingress') : null;
        $websocket = isset($instances['websocket']) ? E2EWireGuardIdentitySet::forRole('websocket') : null;
        $wgEasyPublicKey = trim($instances['gateway']->exec(<<<'SH'
            wg_easy_container="$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn' | head -n 1)"

            if [ -z "$wg_easy_container" ]; then
                wg_easy_container=wg-easy
            fi

            docker exec "$wg_easy_container" wg show wg0 public-key
            SH)->output());

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
    private function retargetTopology(
        array $instances,
        E2EConfig $config,
        SshKeyPair $sshKeyPair,
        E2ETopologyKind $kind,
        bool $sourceMountedCheckout = false,
        ?E2EPhaseTimer $timer = null,
        ?\Closure $beforeDownstreamBake = null,
    ): void {
        $operator = $instances['operator'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($operator === null || $gateway === null) {
            return;
        }

        $bootstrapArguments = sprintf(
            'orbit:internal:bootstrap-gateway-local gateway %s --tld=gateway --public-host=%s --skip-gateway-service-install',
            escapeshellarg(self::GatewayWireGuardIp),
            escapeshellarg($gateway->waitForIpv4()),
        );

        if ($sourceMountedCheckout) {
            $bootstrapCommand =
                E2EGatewayApi::sourceMountedGatewayStateCommand()
                .' && '
                .$this->sourceMountedGatewayArtisanCommand($bootstrapArguments);
            E2ECommand::ssh(
                $gateway,
                'orbit',
                $sshKeyPair,
                'cd /home/orbit/orbit && '.$bootstrapCommand,
                timeoutSeconds: 120,
            );
        } else {
            $this->runGatewayArtisan(
                $gateway,
                $sshKeyPair,
                $bootstrapArguments,
                $sourceMountedCheckout,
                timeoutSeconds: 120,
            );
        }
        E2EGatewayApi::seedOperatorIdentity($gateway, self::OperatorWireGuardIp, $config->operatorUser);

        if ($sourceMountedCheckout) {
            E2EGatewayApi::startSourceMountedGatewayLocalExecutor($gateway);
            $wgEasyHandoff = new E2EWgEasySwarmHandoff;

            try {
                $wgEasyHandoff->stage($gateway);
                $this->runGatewayArtisan(
                    $gateway,
                    $sshKeyPair,
                    'orbit:internal:converge-vpn-dns-runtime gateway',
                    true,
                    timeoutSeconds: 240,
                );
                $wgEasyHandoff->complete($gateway);
            } catch (\Throwable $exception) {
                $wgEasyHandoff->restoreStandalone($gateway);

                throw $exception;
            }
        }

        $this->retargetOperator($operator, $config, $sshKeyPair, $sourceMountedCheckout);

        if ($beforeDownstreamBake !== null) {
            $beforeDownstreamBake();
        }

        $bakeTasks = $this->retargetBakeTasks($instances, $gateway, $kind, $sourceMountedCheckout);

        if ($bakeTasks !== []) {
            IncusParallelHostTasks::run(
                $this->hostFor($instances),
                $bakeTasks,
                $timer ?? new E2EPhaseTimer,
                'retarget.bake',
                timeoutSeconds: 900,
                failureMessage: 'Could not retarget prepared downstream roles',
            );
        }

        // bake-agent-node only registers the gateway row. Write the canonical
        // orbit runtime CLI config on the agent VM so LocalAgentAclEnsure and
        // LocalAgentRuntimeProbe can use /home/orbit/.config/orbit/config.json.
        if (isset($instances['agent'])) {
            $this->writeOrbitRuntimeCliConfig(
                instance: $instances['agent'],
                runtimeUser: 'orbit',
                sshKeyPair: $sshKeyPair,
            );
        }

        if (isset($instances['dev'])) {
            $this->seedAppdevDatabaseAndValkey($gateway, $sshKeyPair, $sourceMountedCheckout);
        }

        if (self::websocketTopologyKind($kind) && isset($instances['dev'])) {
            $this->runGatewayArtisan(
                $gateway,
                $sshKeyPair,
                sprintf(
                    'orbit:internal:bake-websocket-node app-dev-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --valkey-node=app-dev-1 --converge-runtime',
                    escapeshellarg(self::DevWireGuardIp),
                    escapeshellarg(self::DevWireGuardIp),
                    escapeshellarg(self::GatewayWireGuardIp),
                ),
                $sourceMountedCheckout,
                timeoutSeconds: 900,
            );
        }

        $this->prunePreparedGatewayRegistry($instances, $sshKeyPair, $kind, $sourceMountedCheckout);
    }

    /**
     * Build one independent host-key-scan plus bake chain per downstream
     * role. Independent role chains run concurrently; the prod chain bakes
     * its ingress dependency (dedicated edge node or co-hosted prod ingress
     * role) before the prod app role so the serial ordering contract is
     * preserved inside a single concurrent task. The app-dev registry seed
     * and the websocket bake stay serial after the chains because the
     * websocket bake depends on the seeded app-dev registry state.
     *
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function retargetBakeTasks(
        array $instances,
        IncusInstance $gateway,
        E2ETopologyKind $kind,
        bool $sourceMountedCheckout,
    ): array {
        $tasks = [];

        if (isset($instances['dev'])) {
            $tasks['dev'] = implode(' && ', [
                $this->preparedCaddyStartTask($instances['dev']),
                $this->gatewayHostKeyScanTask($gateway, self::DevWireGuardIp),
                $this->gatewayArtisanTask(
                    $gateway,
                    sprintf(
                        'orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit --user=orbit',
                        escapeshellarg(self::DevWireGuardIp),
                        escapeshellarg(self::DevWireGuardIp),
                        escapeshellarg(self::GatewayWireGuardIp),
                    ),
                    $sourceMountedCheckout,
                ),
            ]);
        }

        $prodChain = [];

        if (isset($instances['ingress'])) {
            $prodChain[] = $this->gatewayHostKeyScanTask($gateway, self::IngressWireGuardIp);
            $prodChain[] = $this->gatewayArtisanTask(
                $gateway,
                sprintf(
                    'orbit:internal:bake-ingress-node edge-1 --tld=edge --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                    escapeshellarg(self::IngressWireGuardIp),
                    escapeshellarg(self::IngressWireGuardIp),
                    escapeshellarg(self::GatewayWireGuardIp),
                ),
                $sourceMountedCheckout,
            );
        }

        if (isset($instances['prod'])) {
            $prodChain[] = $this->gatewayHostKeyScanTask($gateway, self::ProdWireGuardIp);

            if (E2EPreparedTopology::prodHostsIngressRole($kind) && ! isset($instances['ingress'])) {
                $prodChain[] = $this->gatewayArtisanTask(
                    $gateway,
                    sprintf(
                        'orbit:internal:bake-ingress-node app-prod-1 --tld=prod --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                        escapeshellarg(self::ProdWireGuardIp),
                        escapeshellarg(self::ProdWireGuardIp),
                        escapeshellarg(self::GatewayWireGuardIp),
                    ),
                    $sourceMountedCheckout,
                );
            }

            $ingressNode = match (true) {
                isset($instances['ingress']) => 'edge-1',
                E2EPreparedTopology::prodHostsIngressRole($kind) => 'app-prod-1',
                default => null,
            };

            $prodChain[] = $this->gatewayArtisanTask(
                $gateway,
                sprintf(
                    'orbit:internal:bake-app-node app-prod-1 --role=app-prod --tld=prod --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
                    escapeshellarg(self::ProdWireGuardIp),
                    escapeshellarg(self::ProdWireGuardIp),
                    escapeshellarg(self::GatewayWireGuardIp),
                    $ingressNode !== null ? ' --ingress-node='.escapeshellarg($ingressNode) : '',
                ),
                $sourceMountedCheckout,
            );
        }

        if ($prodChain !== []) {
            $tasks[isset($instances['prod']) ? 'prod' : 'ingress'] = implode(' && ', $prodChain);
        }

        if (isset($instances['agent'])) {
            $tasks['agent'] = implode(' && ', [
                $this->gatewayHostKeyScanTask($gateway, self::AgentWireGuardIp),
                $this->gatewayArtisanTask(
                    $gateway,
                    sprintf(
                        'orbit:internal:bake-agent-node agent-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --tld=agent',
                        escapeshellarg(self::AgentWireGuardIp),
                        escapeshellarg(self::AgentWireGuardIp),
                        escapeshellarg(self::GatewayWireGuardIp),
                    ),
                    $sourceMountedCheckout,
                ),
            ]);
        }

        return $tasks;
    }

    private function preparedCaddyStartTask(IncusInstance $instance): string
    {
        return sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($instance->name()),
            escapeshellarg('install -d -m 0755 /run/php && docker container start orbit-caddy >/dev/null'),
        );
    }

    private function gatewayHostKeyScanTask(IncusInstance $gateway, string $wireGuardIp): string
    {
        return sprintf(
            'incus exec %s -- runuser -u orbit -- bash -lc %s',
            escapeshellarg($gateway->name()),
            escapeshellarg($this->hostKeyScanLoop($wireGuardIp)),
        );
    }

    private function gatewayArtisanTask(IncusInstance $gateway, string $arguments, bool $sourceMountedCheckout): string
    {
        if ($sourceMountedCheckout) {
            return sprintf(
                'incus exec %s -- runuser -u orbit -- bash -lc %s',
                escapeshellarg($gateway->name()),
                escapeshellarg('cd /home/orbit/orbit && '.$this->sourceMountedGatewayArtisanCommand($arguments)),
            );
        }

        return sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($gateway->name()),
            escapeshellarg(E2ECommand::gatewayArtisanCommand($arguments)),
        );
    }

    private function runGatewayArtisan(
        IncusInstance $gateway,
        SshKeyPair $sshKeyPair,
        string $arguments,
        bool $sourceMountedCheckout,
        int $timeoutSeconds,
    ): void {
        if ($sourceMountedCheckout) {
            E2ECommand::ssh(
                $gateway,
                'orbit',
                $sshKeyPair,
                'cd /home/orbit/orbit && '.$this->sourceMountedGatewayArtisanCommand($arguments),
                timeoutSeconds: $timeoutSeconds,
            );

            return;
        }

        E2ECommand::gatewayArtisan(
            $gateway,
            $arguments,
            'Could not run gateway artisan command during Incus topology retarget',
            timeoutSeconds: $timeoutSeconds,
        );
    }

    private function sourceMountedGatewayArtisanCommand(string $arguments): string
    {
        return implode(' ', [
            'env',
            escapeshellarg('ORBIT_CONFIG_ROOT='.self::GatewayConfigRoot),
            escapeshellarg('DB_CONNECTION=sqlite'),
            escapeshellarg('DB_DATABASE='.self::GatewayDatabase),
            escapeshellarg('SESSION_DRIVER=file'),
            'php apps/gateway/artisan',
            $arguments,
        ]);
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function prunePreparedGatewayRegistry(
        array $instances,
        SshKeyPair $sshKeyPair,
        E2ETopologyKind $kind,
        bool $sourceMountedCheckout,
    ): void {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        $php = E2EPreparedTopology::gatewayRegistryPrunePhp(
            allowedNodeNames: E2EPreparedTopology::gatewayNodeNamesForRoles(array_keys($instances)),
            allowedRolesByNode: E2EPreparedTopology::gatewayAllowedRoleAssignmentsFor($kind, array_keys($instances)),
        );

        $this->runGatewayArtisan(
            $gateway,
            $sshKeyPair,
            'tinker --execute='.escapeshellarg($php),
            $sourceMountedCheckout,
            timeoutSeconds: 120,
        );
    }

    private function retargetOperator(
        IncusInstance $operator,
        E2EConfig $config,
        SshKeyPair $sshKeyPair,
        bool $sourceMountedCheckout,
    ): void {
        $this->writeOrbitRuntimeCliConfig(
            instance: $operator,
            runtimeUser: $config->operatorUser,
            sshKeyPair: $sshKeyPair,
            writeSourceEnv: false,
        );
    }

    /**
     * Write the canonical Orbit CLI JSON config for an orbit-owned topology VM
     * (operator, agent, app hosts). Same contract LocalAgentUserEnsure shim,
     * LocalAgentAclEnsure, and LocalAgentRuntimeProbe read at
     * /home/{runtimeUser}/.config/orbit/config.json.
     */
    private function writeOrbitRuntimeCliConfig(
        IncusInstance $instance,
        string $runtimeUser,
        SshKeyPair $sshKeyPair,
        bool $writeSourceEnv = false,
    ): void {
        $gatewayUrl = 'http://'.self::GatewayWireGuardIp;
        $configDir = "/home/{$runtimeUser}/.config/orbit";
        $configPath = "{$configDir}/config.json";
        $commands = [];

        if ($writeSourceEnv) {
            $commands = [
                'cd '.escapeshellarg("/home/{$runtimeUser}/orbit/apps/cli"),
                'touch .env',
                "grep -Ev '^(ORBIT_GATEWAY_URL|ORBIT_GATEWAY_IDENTITY)=' .env > .env.tmp || true",
                'mv .env.tmp .env',
                sprintf("printf 'ORBIT_GATEWAY_URL=%%s\\n' %s >> .env", escapeshellarg($gatewayUrl)),
                sprintf(
                    'chown %s:%s .env',
                    escapeshellarg($runtimeUser),
                    escapeshellarg($runtimeUser),
                ),
            ];
        }

        $commands = [
            ...$commands,
            sprintf('mkdir -p %s', escapeshellarg($configDir)),
            sprintf('chmod 0700 %s', escapeshellarg($configDir)),
            sprintf(
                'printf %%s %s > %s',
                escapeshellarg($this->cliJsonConfigBody($gatewayUrl)),
                escapeshellarg($configPath),
            ),
            sprintf('chmod 0600 %s', escapeshellarg($configPath)),
            sprintf(
                'chown %s:%s %s %s',
                escapeshellarg($runtimeUser),
                escapeshellarg($runtimeUser),
                escapeshellarg($configDir),
                escapeshellarg($configPath),
            ),
        ];

        E2ECommand::ssh($instance, $runtimeUser, $sshKeyPair, implode(' && ', $commands), timeoutSeconds: 60);
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

    private function seedAppdevDatabaseAndValkey(
        IncusInstance $gateway,
        SshKeyPair $sshKeyPair,
        bool $sourceMountedCheckout,
    ): void {
        $this->runGatewayArtisan(
            $gateway,
            $sshKeyPair,
            'tinker --execute='
                .escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndValkeyPhp(convergeRuntime: true)),
            $sourceMountedCheckout,
            timeoutSeconds: 120,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, IncusInstance>
     */
    private function leaseInstancesFor(E2ETopologyKind $kind, array $instances): array
    {
        if (
            E2EPreparedTopology::prodHostsIngressRole($kind)
            && isset($instances['prod'])
            && ! isset($instances['ingress'])
        ) {
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
    private function seedGatewaySshAccess(array $instances, ?E2EPhaseTimer $timer = null): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        $targets = array_intersect_key($instances, array_flip([
            'gateway',
            'dev',
            'prod',
            'agent',
            'ingress',
            'websocket',
        ]));

        if ($targets === []) {
            return;
        }

        $publicKey = $this->gatewayPublicKey($gateway);
        $authorized = [];
        $tasks = [];

        foreach ($targets as $role => $instance) {
            $instanceName = $instance->name();

            if (isset($authorized[$instanceName])) {
                continue;
            }

            $authorized[$instanceName] = true;
            $tasks[$role] = sprintf(
                'incus exec %s -- sh -lc %s',
                escapeshellarg($instanceName),
                escapeshellarg($this->gatewaySshAuthorizeScript($publicKey)),
            );
        }

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            $tasks,
            $timer ?? new E2EPhaseTimer,
            'gateway-ssh-access',
            timeoutSeconds: 120,
            failureMessage: 'Could not authorize gateway SSH key in Incus clones',
        );
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

    private function gatewaySshAuthorizeScript(string $publicKey): string
    {
        return sprintf(<<<'SH'
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
            SH, escapeshellarg($publicKey));
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function waitForPeerRoutes(array $instances, E2EConfig $config, ?E2EPhaseTimer $timer = null): void
    {
        $tasks = $this->peerRouteTasks($instances, $config);

        if ($tasks === []) {
            return;
        }

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            $tasks,
            $timer ?? new E2EPhaseTimer,
            'network-ready',
            timeoutSeconds: 330,
            failureMessage: 'Prepared topology peer routes never became ready',
        );
    }

    /**
     * One concurrent task per downstream role: a stable gateway SSH probe
     * chained before the operator host-key scan so checkout pinning only runs
     * against reachable peers.
     *
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function peerRouteTasks(array $instances, E2EConfig $config): array
    {
        $gateway = $instances['gateway'] ?? null;
        $operator = $instances['operator'] ?? null;

        if ($gateway === null) {
            return [];
        }

        $tasks = [];

        foreach (['dev', 'prod', 'agent', 'ingress'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $wireGuardIp = $this->wireGuardIpForRole($role);
            $chain = [$this->gatewaySshProbeTask($gateway, $wireGuardIp)];

            if ($operator !== null) {
                $chain[] = $this->operatorHostKeyScanTask($operator, $config, $wireGuardIp);
            }

            $tasks[$role] = implode(' && ', $chain);
        }

        return $tasks;
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
        if ($instances === []) {
            return;
        }

        $clearCommand =
            'for d in /root /home/*; do '
            .'[ -d "$d/.ssh" ] || continue; '
            .'rm -f "$d/.ssh/known_hosts" "$d/.ssh/known_hosts.old"; '
            .'done';
        $tasks = [];

        foreach ($instances as $role => $instance) {
            $tasks[$role] = sprintf(
                'incus exec %s -- sh -lc %s',
                escapeshellarg($instance->name()),
                escapeshellarg($clearCommand),
            );
        }

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            $tasks,
            new E2EPhaseTimer,
            'known-hosts',
            timeoutSeconds: 60,
            failureMessage: 'Could not clear known hosts on prepared clones',
        );
    }

    /**
     * Install a real home-local Orbit launcher that execs the source-mounted
     * checkout binary. Production installs a real file at
     * `$HOME/.local/bin/orbit`; prepared topologies previously used
     * `ln -sfn` into the virtiofs source mount, and `setfacl` on that symlink
     * target fails (stage=binary_acl). A real wrapper on the home filesystem
     * keeps LocalAgentAclEnsure fail-closed while remaining agent-executable.
     *
     * @param  array<string, IncusInstance>  $instances
     */
    private function activateSourceMountedLaunchers(
        array $instances,
        E2EConfig $config,
        ?E2EPhaseTimer $timer = null,
    ): void {
        // Pre-overlay only: bake/retarget need a home launcher before checkout.overlay
        // installs the final /home/orbit/orbit-run runtime. Final proof must not
        // rely on this path — E2ECurrentCheckout rewrites the wrapper after overlay.
        $sourceLauncher = '/home/orbit/orbit/apps/cli/orbit';
        $tasks = [];

        foreach ($instances as $role => $instance) {
            $user = $role === 'operator' ? $config->operatorUser : 'orbit';
            $localBinDirectory = "/home/{$user}/.local/bin";
            $localLauncher = "{$localBinDirectory}/orbit";
            // Real file on the home filesystem (not a symlink into virtiofs).
            // LocalAgentAclEnsure setfacl -m u:agent:r-x requires ACL-capable FS.
            $wrapperContents = <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail

                exec /home/orbit/orbit/apps/cli/orbit "$@"
                BASH;

            $tasks[$role] = sprintf(
                'incus exec %s -- sh -lc %s',
                escapeshellarg($instance->name()),
                escapeshellarg(implode(' && ', [
                    'test -x '.escapeshellarg($sourceLauncher),
                    sprintf(
                        'install -d -m 0755 -o %1$s -g %1$s %2$s',
                        escapeshellarg($user),
                        escapeshellarg($localBinDirectory),
                    ),
                    // Drop any prior symlink into the source mount; setfacl
                    // must target a real file on the home filesystem.
                    'rm -f '.escapeshellarg($localLauncher),
                    'printf %s '.escapeshellarg($wrapperContents).' > '.escapeshellarg($localLauncher),
                    'chmod 0755 '.escapeshellarg($localLauncher),
                    sprintf(
                        'chown %1$s:%1$s %2$s',
                        escapeshellarg($user),
                        escapeshellarg($localLauncher),
                    ),
                    'test -f '.escapeshellarg($localLauncher),
                    'test ! -L '.escapeshellarg($localLauncher),
                    'test -x '.escapeshellarg($localLauncher),
                ])),
            );
        }

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            $tasks,
            $timer ?? new E2EPhaseTimer,
            'source-mounted-launcher',
            timeoutSeconds: 60,
            failureMessage: 'Could not activate source-mounted Orbit launchers',
        );
    }

    /**
     * Prepare the managed agent runtime user and required Orbit CLI/home
     * accessibility on retained agent topologies before product proof begins.
     * Mirrors the product agent-user ensure + ACL posture so tool install/probe
     * does not require ad hoc role repair.
     *
     * @param  array<string, IncusInstance>  $instances
     */
    private function ensureAgentRuntimeReadiness(
        array $instances,
        ?E2EPhaseTimer $timer = null,
    ): void {
        $agent = $instances['agent'] ?? null;

        if (! $agent instanceof IncusInstance) {
            return;
        }

        // Canonical services only: user ensure, ACL ensure, runtime probe.
        // Config must already exist from writeOrbitRuntimeCliConfig during retarget.
        $script = <<<'SH_WRAP'
            set -euo pipefail
            test -f /home/orbit/.config/orbit/config.json
            cd /home/orbit/orbit/apps/cli
            php -r 'require "vendor/autoload.php"; (new App\Services\Nodes\LocalAgentUserEnsure)->ensure(); (new App\Services\Nodes\LocalAgentAclEnsure)->ensure(); $probe = (new App\Services\Nodes\LocalAgentRuntimeProbe)->check(); if (($probe["runtime_user"] ?? false) !== true || ($probe["orbit_cli"] ?? false) !== true) { fwrite(STDERR, json_encode($probe)."\n"); exit(1); }'
            SH_WRAP;

        IncusParallelHostTasks::run(
            $this->hostFor($instances),
            [
                'agent' => sprintf(
                    'incus exec %s -- bash -lc %s',
                    escapeshellarg($agent->name()),
                    escapeshellarg($script),
                ),
            ],
            $timer ?? new E2EPhaseTimer,
            'agent-runtime-readiness',
            timeoutSeconds: 180,
            failureMessage: 'Could not prepare the managed agent runtime user and Orbit CLI access',
        );
    }

    private function gatewaySshProbeTask(IncusInstance $gateway, string $wireGuardIp): string
    {
        $probe = sprintf(<<<'SH'
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
            SH, escapeshellarg($wireGuardIp));

        return sprintf(
            'incus exec %s -- runuser -u orbit -- bash -lc %s',
            escapeshellarg($gateway->name()),
            escapeshellarg($probe),
        );
    }

    private function operatorHostKeyScanTask(IncusInstance $operator, E2EConfig $config, string $wireGuardIp): string
    {
        return sprintf(
            'incus exec %s -- runuser -u %s -- bash -lc %s',
            escapeshellarg($operator->name()),
            escapeshellarg($config->operatorUser),
            escapeshellarg($this->hostKeyScanLoop($wireGuardIp)),
        );
    }

    private function hostKeyScanLoop(string $wireGuardIp): string
    {
        return sprintf(
            'deadline=$((SECONDS+60)); until ssh-keyscan -T 5 -t ed25519,ecdsa,rsa %1$s >/dev/null 2>&1; do if [ "$SECONDS" -ge "$deadline" ]; then ssh-keyscan -T 10 -t ed25519,ecdsa,rsa %1$s; exit 1; fi; sleep 2; done',
            escapeshellarg($wireGuardIp),
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
        $privateKeyPath = "{$workDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";

        $result = $host->run(sprintf(
            'rm -rf %1$s && mkdir -p %1$s && ssh-keygen -t ed25519 -N %2$s -f %3$s -C %4$s >/dev/null',
            escapeshellarg($workDirectory),
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
