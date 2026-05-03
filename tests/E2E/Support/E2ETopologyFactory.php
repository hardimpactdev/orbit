<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2ETopologyFactory
{
    private function __construct(
        private readonly string $provider,
        private readonly string $strategy,
    ) {}

    public static function fromEnvironment(): self
    {
        $provider = getenv('ORBIT_E2E_PROVIDER');
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_STRATEGY');

        return new self(
            is_string($provider) && $provider !== '' ? $provider : 'incus',
            is_string($strategy) && $strategy !== '' ? $strategy : 'minimal',
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

            foreach ($instances as $role => $instance) {
                $primaryUser = match ($role) {
                    'control' => $config->controlUser,
                    default => 'orbit',
                };

                $timer->measure("ssh-authorize.{$role}", function () use ($instance, $config, $primaryUser, $sshKeyPair): void {
                    $instance->authorizeSsh($config->bootstrapUser, $sshKeyPair);

                    if ($primaryUser !== $config->bootstrapUser) {
                        $instance->authorizeSsh($primaryUser, $sshKeyPair);
                    }
                });
                $timer->measure("ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }

            $timer->measure('wireguard', fn () => $this->reestablishWireGuard($instances));

            $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $resolved, $runId): array {
                $newInstances = IncusTopologyTemplate::clone($host, $resolved, $runId, $cycleTimer);
                $cycleTimer->measure('wireguard', fn () => $this->reestablishWireGuard($newInstances));

                return $newInstances;
            };

            return new E2ETopologyLease(
                kind: $resolved,
                control: $instances['control'],
                gateway: $instances['gateway'] ?? null,
                dev: $instances['dev'] ?? null,
                prod: $instances['prod'] ?? null,
                sshKeyPair: $sshKeyPair,
                rebuild: $rebuild,
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
