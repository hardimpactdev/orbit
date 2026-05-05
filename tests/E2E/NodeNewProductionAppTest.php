<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EBaseProvisioner;
use Tests\E2E\Support\E2ECommand;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2EGatewayApi;
use Tests\E2E\Support\E2EImage;
use Tests\E2E\Support\E2ENetwork;
use Tests\E2E\Support\E2ENodeProbe;
use Tests\E2E\Support\E2EProvisioningBundle;
use Tests\E2E\Support\E2ERun;
use Tests\E2E\Support\IncusProvider;
use Tests\E2E\Support\ProviderPool;

pest()->group('e2e-provision');

it('provisions a production app node from a provisioned control VM through a provisioned gateway VM', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank, E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'node-new-prodapp');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $provisioner = new E2EBaseProvisioner($provider, $bundle);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionStep('provision control from base', fn () => $provisioner->provision($run, 'control', 'control', $config->controlUser));
        $gateway = e2eProvisionStep('provision gateway from base', fn () => $provisioner->provision($run, 'gateway', 'gateway'));
        $app = $run->launchBlank('app');

        $control->authorizeSsh($config->controlUser, $key);
        $gateway->authorizeSsh('orbit', $key);
        $app->authorizeSsh($config->bootstrapUser, $key);

        $control->waitForSsh($config->controlUser, $key);
        $gateway->waitForSsh('orbit', $key);
        $app->waitForSsh($config->bootstrapUser, $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();
        $appIp = $app->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp)
            ->and($controlIp)->not->toBe($appIp)
            ->and($gatewayIp)->not->toBe($appIp);

        E2ENetwork::assignWireGuardIp($control, '10.6.0.3');
        E2ENetwork::assignWireGuardIp($gateway, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');
        E2EGatewayApi::seedControlIdentity($gateway, $controlIp, $config->controlUser);
        E2EGatewayApi::installRootSshKey($gateway, $key);
        E2EGatewayApi::start($gateway, 'node-new-prodapp');
        E2EGatewayApi::waitForGatewayApi($control, $config->controlUser, $key);

        $gatewayAdd = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:add 10.6.0.2 --json",
            timeoutSeconds: 600,
        );

        $gatewayAddPayload = json_decode(trim($gatewayAdd->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($gatewayAddPayload['success']['data']['result']['action'])->toBe('added');

        $nodeNew = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit node:new app-prod-1 --role=app --host={$appIp} --environment=production --ssh-user={$config->bootstrapUser} --json",
            timeoutSeconds: 1800,
        );

        $payload = json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['node']['name'])->toBe('app-prod-1')
            ->and($payload['success']['data']['node']['role'])->toBe('app')
            ->and($payload['success']['data']['node']['environment'])->toBe('production')
            ->and($payload['success']['data']['node']['tld'])->toBeNull()
            ->and($payload['success']['data']['node']['addresses']['wireguard'])->toBe('10.6.0.4')
            ->and($payload['success']['data'])->not->toHaveKey('development_tld');

        $gatewayNode = E2EGatewayApi::getNode($gateway, 'app-prod-1');

        expect($gatewayNode['name'])->toBe('app-prod-1')
            ->and($gatewayNode['role'])->toBe('app')
            ->and($gatewayNode['environment'])->toBe('production')
            ->and($gatewayNode['tld'])->toBeNull()
            ->and($gatewayNode['host'])->toBe($appIp)
            ->and($gatewayNode['wireguard_address'])->toBe('10.6.0.4')
            ->and($gatewayNode['gateway_endpoint'])->toBe('10.6.0.2')
            ->and($gatewayNode['ssh_user'])->toBe($config->bootstrapUser)
            ->and($gatewayNode['user'])->toBe('orbit')
            ->and((bool) $gatewayNode['is_local'])->toBeFalse();

        E2ENodeProbe::assertOrbitInstalled($app);

        $controlNodeCount = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && php artisan tinker --execute='echo \\App\\Models\\Node::query()->count();'",
        );

        expect(trim($controlNodeCount->output()))->toBe('0');
        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});
