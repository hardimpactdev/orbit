<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class IncusTopologyProvider implements E2ETopologyProvider
{
    public function __construct(
        private E2EConfig $config,
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
        $host = IncusHostPool::fromEnvironment($this->config)->firstAvailableFor($kind);

        if ($host === null) {
            return ProviderAvailability::unavailable("prepared topology {$kind->value} is not available on any Incus host");
        }

        return ProviderAvailability::available("prepared topology {$kind->value} is available");
    }

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
    {
        $pool = IncusHostPool::fromEnvironment($this->config);
        $host = $timer->measure('availability', fn () => $pool->firstAvailableFor($kind));

        if ($host === null) {
            throw new \RuntimeException("Prepared topology {$kind->value} is not available on any Incus host");
        }

        $instances = IncusTopologyTemplate::clone($host, $kind, $runId, $timer);

        $sshKeyPair = $this->createSshKeyPair($host, $runId);
        $primaryUsers = $this->prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options);
        $snapshotReset = $this->prepareSnapshotReset($host, $instances, $primaryUsers, $sshKeyPair, $timer);

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $sshKeyPair, $options): array {
            $newInstances = IncusTopologyTemplate::clone($host, $kind, $runId, $cycleTimer);
            $newPrimaryUsers = $this->prepareInstances($newInstances, $this->config, $sshKeyPair, $cycleTimer, $options);

            return [
                'instances' => $newInstances,
                'snapshotReset' => $this->prepareSnapshotReset($host, $newInstances, $newPrimaryUsers, $sshKeyPair, $cycleTimer),
            ];
        };

        return new E2ETopologyLease(
            kind: $kind,
            control: $instances['control'],
            gateway: $instances['gateway'] ?? null,
            dev: $instances['dev'] ?? null,
            prod: $instances['prod'] ?? null,
            sshKeyPair: $sshKeyPair,
            rebuild: $rebuild,
            snapshotReset: $snapshotReset,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function prepareInstances(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): array
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

        if ($options->startGatewayApi) {
            $timer->measure('wireguard', fn () => $this->reestablishWireGuard($instances));
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
                'control' => $config->controlUser,
                default => 'orbit',
            };
        }

        return $users;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function prepareSnapshotReset(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer): ?\Closure
    {
        if (! $this->shouldPrepareSnapshotReset()) {
            return null;
        }

        $strategy = $this->resetStrategy();

        foreach ($instances as $role => $instance) {
            if ($strategy === 'stateful-restore') {
                $timer->measure("snapshot-stateful.{$role}", fn () => $instance->snapshotStatefully('lease-warm'));

                continue;
            }

            $timer->measure("snapshot.{$role}", fn () => $instance->snapshot('lease-clean'));
        }

        return $strategy === 'stateful-restore'
            ? $this->statefulResetFor($host, $instances, $primaryUsers, $sshKeyPair)
            : $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair);
    }

    private function shouldPrepareSnapshotReset(): bool
    {
        return in_array($this->resetStrategy(), ['snapshot-restore', 'stateful-restore'], true);
    }

    private function resetStrategy(): string
    {
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_RESET');

        return is_string($strategy) && $strategy !== '' ? $strategy : 'fresh-clone';
    }

    private function snapshotResetFor(array $instances, array $primaryUsers, SshKeyPair $sshKeyPair): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($instances, $primaryUsers, $sshKeyPair): void {
            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
                $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
                $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
            }

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            $cycleTimer->measure('reset.wireguard', fn () => $this->reestablishWireGuard($instances));

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
    private function reestablishWireGuard(array $instances): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        E2ENetwork::assignWireGuardIp($control, '10.6.0.3');
        E2ENetwork::assignWireGuardIp($gateway, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');

        E2EGatewayApi::start($gateway, 'topology-lease');
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
