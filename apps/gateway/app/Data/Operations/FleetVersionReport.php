<?php

declare(strict_types=1);

namespace App\Data\Operations;

/**
 * Result of the `update:all` fleet version probe.
 *
 * Records the target release version, the gateway's tracked image version, and
 * each selected workload node's tracked CLI version. `outdatedCount` is the
 * number of installations — gateway plus workload nodes — whose installed
 * artifact identity is unknown or differs from the desired manifest artifact.
 */
final readonly class FleetVersionReport
{
    /**
     * @param  array<string, string|null>  $nodeVersions  Keyed by node name.
     */
    public function __construct(
        public string $targetVersion,
        public ?string $gatewayVersion,
        public array $nodeVersions,
        public int $outdatedCount,
    ) {}

    public function allCurrent(): bool
    {
        return $this->outdatedCount === 0;
    }

    /**
     * Stable human-progress row targets for an outdated fleet check: gateway,
     * local, then selected workload node names in selector order.
     *
     * @return list<string>
     */
    public function progressUpdateTargets(): array
    {
        return array_merge(
            ['gateway', 'local'],
            array_keys($this->nodeVersions),
        );
    }
}
