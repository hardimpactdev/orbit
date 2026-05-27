<?php

declare(strict_types=1);

use App\E2E\Support\E2EImage;
use App\E2E\Support\E2ERun;
use App\E2E\Support\ProviderPool;

pest()->group('e2e-provision');

it('launches a base VM, reaches it over SSH, and destroys it', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $provider = $selection->provider();
    $config = $provider->config();
    $run = E2ERun::start($provider, 'base-lifecycle');
    $passed = false;

    try {
        $key = $run->createSshKeyPair();
        $vm = $run->launchBase('base');

        $vm->authorizeSsh($config->bootstrapUser, $key);
        $vm->waitForSsh($config->bootstrapUser, $key);

        $result = $vm->ssh($config->bootstrapUser, $key, 'test "$(uname -s)" = Linux && test -r /etc/os-release');

        expect($result->successful())->toBeTrue($result->errorOutput());

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
    }
});
