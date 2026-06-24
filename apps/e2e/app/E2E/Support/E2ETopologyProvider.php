<?php

declare(strict_types=1);

namespace App\E2E\Support;

interface E2ETopologyProvider
{
    public function name(): string;

    public function capabilities(): E2ETopologyCapabilities;

    public function availability(E2ETopologyKind $kind): ProviderAvailability;

    public function acquire(
        E2ETopologyKind $kind,
        string $runId,
        E2EPhaseTimer $timer,
        E2ETopologyAcquisitionOptions $options,
    ): E2ETopologyLease;
}
