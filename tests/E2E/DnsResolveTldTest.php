<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\ProviderPool;

pest()->group('e2e-provisioning');

it('reports unsupported_platform on Linux when given valid arguments', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Control);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $provider = $selection->provider();
    $config = $provider->config();
    $run = E2ERun::start($provider, 'dns-resolve-tld');

    try {
        $key = $run->createSshKeyPair();
        $control = $run->launchControl('control');

        $control->authorizeSsh($config->controlUser, $key);
        $control->waitForSsh($config->controlUser, $key);

        $result = $control->ssh($config->controlUser, $key, 'orbit dns:resolve-tld test 10.6.0.7 --json');

        expect($result->successful())->toBeFalse(
            'Expected dns:resolve-tld to fail on Linux, but it succeeded. Output: '.$result->output().$result->errorOutput()
        );

        $output = trim($result->output());
        $json = json_decode($output, true);

        expect($json)->toHaveKey('error');
        expect($json['error'])->toHaveKey('code');
        expect($json['error']['code'])->toContain('unsupported_platform');

        $result = $control->ssh($config->controlUser, $key, 'orbit dns:resolve-tld --json');

        expect($result->successful())->toBeFalse(
            'Expected dns:resolve-tld without args to fail validation, but it succeeded. Output: '.$result->output().$result->errorOutput()
        );

        $output = trim($result->output());
        $json = json_decode($output, true);

        expect($json)->toHaveKey('error');
        expect($json['error'])->toHaveKey('code');
        expect($json['error']['code'])->toBe('validation_failed');
    } finally {
        $run->cleanup();
    }
});
