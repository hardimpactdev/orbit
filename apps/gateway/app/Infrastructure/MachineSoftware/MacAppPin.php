<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The macOS arm64 app of an agent folder part's release (docs/reference/development-containers.md#conn-server-pin): the zip of its
 * signed `.app` bundle, and the bundle id and Developer ID team that the bundle's signature must name. Container hosts never read
 * it; the Conn Server rollout to Mac Nodes does.
 */
final readonly class MacAppPin
{
    public function __construct(
        public ArtifactPin $artifact,
        public string $bundleId,
        public string $teamId,
    ) {}
}
