<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2ETopologyFactory
{
    /**
     * @param  array<string, string>|null  $sshUsers
     */
    private function __construct(
        private readonly string $provider,
        private readonly string $strategy,
        private readonly ?array $sshUsers = null,
    ) {}

    public static function fromEnvironment(): self
    {
        $provider = getenv('ORBIT_E2E_PROVIDER');
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_STRATEGY');

        return new self(
            provider: is_string($provider) && $provider !== '' ? $provider : 'incus',
            strategy: is_string($strategy) && $strategy !== '' ? $strategy : 'minimal',
            sshUsers: null,
        );
    }

    /**
     * @param  array<string, string>  $sshUsers
     */
    public function withSshUsers(array $sshUsers): self
    {
        return new self(
            provider: $this->provider,
            strategy: $this->strategy,
            sshUsers: $sshUsers,
        );
    }

    public function require(E2ETopologyKind $kind): E2ETopologyLease
    {
        $resolved = $this->resolveKind($kind);

        if ($this->provider !== 'incus') {
            test()->markTestSkipped('Prepared topology not available for provider: '.$this->provider);
        }

        $timer = new E2EPhaseTimer;

        try {
            $config = E2EConfig::fromEnvironment();
            $pool = IncusHostPool::fromEnvironment($config);
            $host = $timer->measure('availability', fn () => $pool->firstAvailableFor($resolved));

            if ($host === null) {
                test()->markTestSkipped('Prepared topology not available: '.$resolved->value);
            }

            $runId = E2ERun::id();
            $instances = IncusTopologyTemplate::clone($host, $resolved, $runId, $timer);

            $sshKeyPair = $this->createSshKeyPair($host, $runId);
            $primaryUsers = $this->prepareInstances($instances, $config, $sshKeyPair, $timer);
            $snapshotReset = $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair);

            $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $resolved, $runId, $config, $sshKeyPair): array {
                $newInstances = IncusTopologyTemplate::clone($host, $resolved, $runId, $cycleTimer);
                $newPrimaryUsers = $this->prepareInstances($newInstances, $config, $sshKeyPair, $cycleTimer);

                return [
                    'instances' => $newInstances,
                    'snapshotReset' => $this->snapshotResetFor($newInstances, $newPrimaryUsers, $sshKeyPair),
                ];
            };

            return new E2ETopologyLease(
                kind: $resolved,
                control: $instances['control'],
                gateway: $instances['gateway'] ?? null,
                dev: $instances['dev'] ?? null,
                prod: $instances['prod'] ?? null,
                sshKeyPair: $sshKeyPair,
                rebuild: $rebuild,
                snapshotReset: $snapshotReset,
            );
        } finally {
            $timer->flush('acquire');
        }
    }

    public function resolveKind(E2ETopologyKind $kind): E2ETopologyKind
    {
        if ($this->strategy !== 'superset') {
            return $kind;
        }

        return match ($kind) {
            E2ETopologyKind::Control,
            E2ETopologyKind::ControlGateway,
            E2ETopologyKind::ControlGatewayDev => E2ETopologyKind::ControlGatewayDevProd,
            default => $kind,
        };
    }

    /**
     * @param  array<string, E2EInstance>  $instances
     * @return array<string, string>
     */
    private function prepareInstances(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer): array
    {
        $sshUsers = $this->sshUsersFor($instances, $config);
        $primaryUsers = [];

        foreach ($sshUsers as $role => $primaryUser) {
            $instance = $instances[$role] ?? null;

            if ($instance === null) {
                continue;
            }

            $primaryUsers[$role] = $primaryUser;

            $timer->measure("ssh-authorize.{$role}", function () use ($instance, $config, $primaryUser, $sshKeyPair): void {
                $instance->authorizeSsh($config->bootstrapUser, $sshKeyPair);

                if ($primaryUser !== $config->bootstrapUser) {
                    $instance->authorizeSsh($primaryUser, $sshKeyPair);
                }
            });

            $timer->measure("ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
        }

        if (isset($instances['control'], $primaryUsers['control'])) {
            $timer->measure('control-identity', fn () => E2EControlIdentity::ensure(
                $instances['control'],
                $primaryUsers['control'],
                $sshKeyPair,
            ));
        }

        foreach ($instances as $role => $instance) {
            $timer->measure("snapshot.{$role}", fn () => $instance->snapshot('lease-clean'));
        }

        $timer->measure('wireguard', fn () => $this->reestablishWireGuard($instances));

        return $primaryUsers;
    }

    /**
     * @param  array<string, E2EInstance>  $instances
     * @return array<string, string>
     */
    private function sshUsersFor(array $instances, E2EConfig $config): array
    {
        if ($this->sshUsers !== null) {
            return $this->sshUsers;
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
     * @param  array<string, E2EInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
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
     * Re-apply the synthetic WireGuard topology that was set up during
     * template build. The 10.6.0.x addresses are not persistent in the
     * snapshots, so each clone needs the IP+routes re-applied before
     * gateway forwarding can work.
     *
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
