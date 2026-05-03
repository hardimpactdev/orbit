<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

interface E2EProvider
{
    public function name(): string;

    public function config(): E2EConfig;

    /**
     * @param  list<E2EImage>  $images
     */
    public function availability(array $images): ProviderAvailability;

    public function startRun(string $label): E2ERun;

    public function createSshKeyPair(E2ERun $run): SshKeyPair;

    public function launch(E2ERun $run, E2EImage $image, string $suffix): E2EInstance;

    /**
     * @param  list<E2EInstance>  $instances
     */
    public function cleanup(E2ERun $run, array $instances): void;

    public function supportsPreparedTopologies(): bool;

    public function topologyAvailability(E2ETopologyKind $kind): ProviderAvailability;

    public function acquireTopology(E2ETopologyKind $kind, string $label): E2ETopologyLease;
}
