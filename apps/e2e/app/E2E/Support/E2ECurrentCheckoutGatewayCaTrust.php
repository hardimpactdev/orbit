<?php

declare(strict_types=1);

namespace App\E2E\Support;

/**
 * How retained current-checkout harness supplies public gateway root CA for CLI trust.
 *
 * Either an explicit PEM from the topology control path, or the gateway node's
 * local public root.crt. Never private key material.
 */
final readonly class E2ECurrentCheckoutGatewayCaTrust
{
    public function __construct(
        public ?string $rootCaPem = null,
        public bool $useLocalGatewayRootCa = false,
    ) {}

    public static function fromPem(string $rootCaPem): self
    {
        return new self(rootCaPem: $rootCaPem);
    }

    public static function localGatewayRootCertificate(): self
    {
        return new self(useLocalGatewayRootCa: true);
    }
}
