<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ETopologyFactory
{
    /**
     * @param  array<string, string>|null  $sshUsers
     */
    private function __construct(
        private string $strategy,
        private ?array $sshUsers = null,
        private ?E2ETopologyCapabilities $capabilityRequirements = null,
        private bool $startGatewayApi = false,
    ) {}

    public static function fromEnvironment(): self
    {
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_STRATEGY');

        return new self(
            strategy: is_string($strategy) && $strategy !== '' ? $strategy : 'minimal',
        );
    }

    public function requireCapabilities(E2ETopologyCapabilities $required): self
    {
        return new self(
            strategy: $this->strategy,
            sshUsers: $this->sshUsers,
            capabilityRequirements: $required,
            startGatewayApi: $this->startGatewayApi,
        );
    }

    /**
     * @param  array<string, string>  $sshUsers
     */
    public function withSshUsers(array $sshUsers): self
    {
        return new self(
            strategy: $this->strategy,
            sshUsers: $sshUsers,
            capabilityRequirements: $this->capabilityRequirements,
            startGatewayApi: $this->startGatewayApi,
        );
    }

    public function withGatewayApi(): self
    {
        return new self(
            strategy: $this->strategy,
            sshUsers: $this->sshUsers,
            capabilityRequirements: $this->capabilityRequirements,
            startGatewayApi: true,
        );
    }

    public function require(E2ETopologyKind $kind): E2ETopologyLease
    {
        $resolved = $this->resolveKind($kind);
        $timer = new E2EPhaseTimer;

        try {
            $config = E2EConfig::fromEnvironment();
            $selection = E2ETopologyProviderPool::fromEnvironment($config)->select($resolved, $this->capabilityRequirements);

            if (! $selection->available()) {
                throw new E2ETopologyUnavailable('Prepared topology not available: '.$selection->message);
            }

            return $selection->provider()->acquire(
                $resolved,
                E2ERun::id(),
                $timer,
                new E2ETopologyAcquisitionOptions(
                    sshUsers: $this->sshUsers,
                    startGatewayApi: $this->startGatewayApi,
                ),
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
}
