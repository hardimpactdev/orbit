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

        test()->markTestSkipped('Prepared topology not available: '.$resolved->value);
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
