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

        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);
        $host = $pool->firstAvailableFor($resolved);

        if ($host === null) {
            test()->markTestSkipped('Prepared topology not available: '.$resolved->value);
        }

        $runId = E2ERun::id();
        $instances = IncusTopologyTemplate::clone($host, $resolved, $runId);

        $sshKeyPair = $this->createSshKeyPair($host, $runId);

        foreach ($instances as $instance) {
            $instance->authorizeSsh($config->bootstrapUser, $sshKeyPair);
            $instance->waitForSsh($config->bootstrapUser, $sshKeyPair);
        }

        return new E2ETopologyLease(
            kind: $resolved,
            control: $instances['control'],
            gateway: $instances['gateway'] ?? null,
            dev: $instances['dev'] ?? null,
            prod: $instances['prod'] ?? null,
            sshKeyPair: $sshKeyPair,
        );
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
