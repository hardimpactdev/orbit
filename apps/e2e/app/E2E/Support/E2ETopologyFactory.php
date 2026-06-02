<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ETopologyFactory
{
    /**
     * @param  array<string, string>|null  $sshUsers
     */
    private function __construct(
        private ?array $sshUsers = null,
        private ?E2ETopologyCapabilities $capabilityRequirements = null,
        private bool $startGatewayApi = false,
        private bool $sourceMountedCheckout = false,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self;
    }

    public function requireCapabilities(E2ETopologyCapabilities $required): self
    {
        return new self(
            sshUsers: $this->sshUsers,
            capabilityRequirements: $required,
            startGatewayApi: $this->startGatewayApi,
            sourceMountedCheckout: $this->sourceMountedCheckout,
        );
    }

    /**
     * @param  array<string, string>  $sshUsers
     */
    public function withSshUsers(array $sshUsers): self
    {
        return new self(
            sshUsers: $sshUsers,
            capabilityRequirements: $this->capabilityRequirements,
            startGatewayApi: $this->startGatewayApi,
            sourceMountedCheckout: $this->sourceMountedCheckout,
        );
    }

    public function withGatewayApi(): self
    {
        return new self(
            sshUsers: $this->sshUsers,
            capabilityRequirements: $this->capabilityRequirements,
            startGatewayApi: true,
            sourceMountedCheckout: $this->sourceMountedCheckout,
        );
    }

    public function withSourceMountedCheckout(): self
    {
        return new self(
            sshUsers: $this->sshUsers,
            capabilityRequirements: $this->capabilityRequirements,
            startGatewayApi: $this->startGatewayApi,
            sourceMountedCheckout: true,
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
                $this->acquisitionOptions(),
            );
        } finally {
            $timer->flush('acquire');
        }
    }

    public function resolveKind(E2ETopologyKind $kind): E2ETopologyKind
    {
        return $kind;
    }

    private function acquisitionOptions(): E2ETopologyAcquisitionOptions
    {
        return new E2ETopologyAcquisitionOptions(
            sshUsers: $this->sshUsers,
            startGatewayApi: $this->startGatewayApi,
            sourceMountedCheckout: $this->sourceMountedCheckout,
        );
    }
}
