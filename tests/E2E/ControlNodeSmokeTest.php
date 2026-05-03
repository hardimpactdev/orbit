<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\ProviderPool;

it('launches a ready control VM and runs orbit', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Control);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $provider = $selection->provider();
    $config = $provider->config();
    $run = E2ERun::start($provider, 'control-smoke');

    try {
        $key = $run->createSshKeyPair();
        $control = $run->launchControl('control');

        $control->authorizeSsh($config->controlUser, $key);
        $control->waitForSsh($config->controlUser, $key);

        $result = $control->ssh($config->controlUser, $key, "orbit --version | grep -F 'Orbit'");

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
    } finally {
        $run->cleanup();
    }
});
