<?php

declare(strict_types=1);

use App\E2E\Support\E2EBaseProvisioner;
use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ENetwork;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2ERun;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderPool;
use App\E2E\Support\SshKeyPair;

pest()->group('e2e-provision');

it('derives the gateway IP and joins a provisioned gateway from a provisioned control VM', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'gateway-add');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $provisioner = new E2EBaseProvisioner($provider, $bundle);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionStep('provision control from base', fn () => $provisioner->provision($run, 'control', 'control', $config->controlUser));
        $gateway = e2eProvisionStep('provision gateway from base', fn () => $provisioner->provision($run, 'gateway', 'gateway'));

        $control->authorizeSsh($config->controlUser, $key);
        $gateway->authorizeSsh('orbit', $key);

        $control->waitForSsh($config->controlUser, $key);
        $gateway->waitForSsh('orbit', $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp);

        E2ENetwork::assignWireGuardIp($control, '10.6.0.3');
        E2ENetwork::assignWireGuardIp($gateway, '10.6.0.2');
        E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
        E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');
        E2EGatewayApi::seedControlIdentity($gateway, $controlIp, $config->controlUser);
        E2EGatewayApi::start($gateway, 'gateway-add');
        E2EGatewayApi::waitForGatewayApi($control, $config->controlUser, $key);

        $gatewayAdd = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:add --json",
            timeoutSeconds: 600,
        );

        $payload = json_decode(trim($gatewayAdd->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result']['action'])->toBe('added')
            ->and($payload['success']['data']['gateway']['name'])->toBe('gateway')
            ->and($payload['success']['data']['gateway']['addresses']['wireguard'])->toBe('10.6.0.2')
            ->and($payload['success']['data']['local_node']['name'])->toBe('control-1')
            ->and($payload['success']['data']['local_node']['addresses']['wireguard'])->toBe('10.6.0.3')
            ->and($payload['success']['data']['local_onboarding']['gateway_trust'])->toBe('trusted')
            ->and($payload['success']['data']['local_onboarding']['gateway_config'])->toBe('stored')
            ->and($payload['success']['data']['local_onboarding']['gateway_api'])->toBe('verified');

        $settings = gatewayAddE2EControlSettings($control, $config->controlUser, $key);

        expect($settings['gateway_url'])->toBe('https://10.6.0.2')
            ->and($settings['gateway_wg_ip'])->toBe('10.6.0.2')
            ->and($settings['ca_sha256'])->toBeString()->not->toBeEmpty()
            ->and($settings['ca_pem_path'])->toContain('storage/app/orbit/gateway-ca/orbit.crt');

        $trustStoreFile = $control->ssh(
            $config->controlUser,
            $key,
            'test -f /usr/local/share/ca-certificates/orbit-gateway-ca-orbit.crt',
        );

        expect($trustStoreFile->successful())->toBeTrue($trustStoreFile->output().$trustStoreFile->errorOutput());

        $localNodeMirrorCount = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && php artisan tinker --execute='echo \\App\\Models\\Node::query()->count();'",
        );

        expect(trim($localNodeMirrorCount->output()))->toBe('0');

        $rerun = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:add --json",
            timeoutSeconds: 600,
        );

        $rerunPayload = json_decode(trim($rerun->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rerunSettings = gatewayAddE2EControlSettings($control, $config->controlUser, $key);
        $rerunLocalNodeMirrorCount = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && php artisan tinker --execute='echo \\App\\Models\\Node::query()->count();'",
        );

        expect($rerunPayload['success']['data']['result']['action'])->toBe('converged')
            ->and($rerunPayload['success']['data']['gateway']['addresses']['wireguard'])->toBe('10.6.0.2')
            ->and($rerunPayload['success']['data']['local_node']['addresses']['wireguard'])->toBe('10.6.0.3')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_trust'])->toBe('already_trusted')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_config'])->toBe('already_stored')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_api'])->toBe('verified')
            ->and($rerunSettings)->toBe($settings)
            ->and(trim($rerunLocalNodeMirrorCount->output()))->toBe('0');

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

/**
 * @return array<string, mixed>
 */
function gatewayAddE2EControlSettings(E2EInstance $control, string $controlUser, SshKeyPair $key): array
{
    $php = <<<'PHP'
$settings = \App\Models\LocalGatewaySettings::current();
echo json_encode([
    'gateway_url' => $settings->gateway_url,
    'gateway_wg_ip' => $settings->gateway_wg_ip,
    'ca_sha256' => $settings->ca_sha256,
    'ca_pem_path' => $settings->ca_pem_path,
], JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'cd /home/'.$controlUser.'/orbit && php artisan tinker --execute='.escapeshellarg($php),
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}
