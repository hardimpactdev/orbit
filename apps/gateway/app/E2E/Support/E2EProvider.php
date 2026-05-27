<?php

declare(strict_types=1);

namespace App\E2E\Support;

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
}
