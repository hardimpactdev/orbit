<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\ProviderPool;

it('provisions the first gateway from a ready control VM', function (): void {
    $selection = ProviderPool::fromEnvironment()->select(E2EImage::Blank, E2EImage::Control);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $provider = $selection->provider();
    $config = $provider->config();
    $run = E2ERun::start($provider, 'node-new-gateway');

    try {
        $key = $run->createSshKeyPair();
        $control = $run->launchControl('control');
        $gateway = $run->launchBlank('gateway');

        $control->authorizeSsh($config->controlUser, $key);
        $gateway->authorizeSsh($config->bootstrapUser, $key);
        $control->copyFileToInstance($key->privateKeyPath, "/home/{$config->controlUser}/.ssh/id_ed25519");
        $control->exec("chown {$config->controlUser}:{$config->controlUser} /home/{$config->controlUser}/.ssh/id_ed25519 && chmod 600 /home/{$config->controlUser}/.ssh/id_ed25519");

        $control->waitForSsh($config->controlUser, $key);
        $gateway->waitForSsh($config->bootstrapUser, $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp);

        $nodeNew = $control->ssh(
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit node:new gateway-1 --role=gateway --host={$gatewayIp} --ssh-user={$config->bootstrapUser} --control-name=control-1 --json",
            timeoutSeconds: 1800,
        );

        expect($nodeNew->successful())->toBeTrue($nodeNew->output().$nodeNew->errorOutput());

        $payload = json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node']['role'])->toBe('gateway')
            ->and($payload['success']['data']['local_control_node']['name'])->toBe('control-1');

        $registry = $control->ssh($config->controlUser, $key, "cd /home/{$config->controlUser}/orbit && orbit node:list --json");

        expect($registry->successful())->toBeTrue($registry->output().$registry->errorOutput())
            ->and($registry->output())->toContain('gateway-1')
            ->and($registry->output())->toContain('control-1');

        $gatewayInstall = $gateway->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/artisan');
        $gatewayVersion = $gateway->exec("sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'");

        expect($gatewayInstall->successful())->toBeTrue($gatewayInstall->errorOutput())
            ->and($gatewayVersion->successful())->toBeTrue($gatewayVersion->output().$gatewayVersion->errorOutput());
    } finally {
        $run->cleanup();
    }
});
