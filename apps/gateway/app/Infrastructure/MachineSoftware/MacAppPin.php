<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The ZIP of a signed macOS app and the identity its signature must name. Only the host rollout consumes it.
 */
final readonly class MacAppPin
{
    public function __construct(
        public ArtifactPin $artifact,
        public string $bundleId,
        public string $teamId,
    ) {}
}
