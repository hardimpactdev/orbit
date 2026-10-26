<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/** A Node snapshot retained only for fleet rollback. Rust owns its interpretation. */
final readonly class MetricsExporterState
{
    public function __construct(
        public ?string $configuration,
        public bool $serviceActive,
        public ?string $firewallSource,
    ) {}

    /** @return array{configuration: ?string, service_active: bool, firewall_source: ?string} */
    public function toAgent(): array
    {
        return [
            'configuration' => $this->configuration,
            'service_active' => $this->serviceActive,
            'firewall_source' => $this->firewallSource,
        ];
    }
}
