<?php

declare(strict_types=1);

use App\E2E\Support\E2EBaseProvisioner;
use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EImage;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EProvisioningBundle;
use App\E2E\Support\E2ERun;
use App\E2E\Support\IncusProvider;
use App\E2E\Support\ProviderPool;
use App\E2E\Support\SshKeyPair;

pest()->group('e2e-provision');

it('NodeNewGatewayApiVerify proves first-gateway API verification over WireGuard', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'node-new-gateway-api-verify');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $provisioner = new E2EBaseProvisioner($provider, $bundle);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionStep('provision control from base', fn () => $provisioner->provision($run, 'control', 'control', $config->controlUser));
        $gateway = e2eProvisionStep('launch base gateway', fn () => $run->launchBase('gateway'));

        e2eProvisionStep('wait for gateway cloud-init', fn () => $provider->host->waitForCloudInit($gateway->name()));
        e2eProvisionStep('authorize SSH between control and gateway', fn () => nodeNewGatewayApiVerifyAuthorizeSsh($control, $gateway, $config->controlUser, $config->bootstrapUser, $key));

        $control->waitForSsh($config->controlUser, $key);
        $gateway->waitForSsh($config->bootstrapUser, $key);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp);

        $command = "cd /home/{$config->controlUser}/orbit && php artisan node:new gateway-1 --role=gateway --host={$gatewayIp} --ssh-user={$config->bootstrapUser} --control-name=control-1 --json";
        $nodeNew = e2eProvisionStep('run node:new gateway', fn () => E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            $command,
            timeoutSeconds: 1800,
        ));

        $payload = json_decode(trim($nodeNew->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['local_onboarding']['gateway_api'])->toBe('verified')
            ->and($payload['success']['data']['local_onboarding']['gateway_trust'])->toBe('trusted')
            ->and($payload['success']['data']['gateway_trust']['ca_pem_path'])->toBe("/home/{$config->controlUser}/orbit/storage/app/orbit/trust/gateway-1-ca.crt")
            ->and($payload['success']['data']['gateway_trust']['ca_sha256'])->toMatch('/^[a-f0-9]{64}$/');

        $settings = e2eProvisionStep('read persisted gateway settings', fn () => nodeNewGatewayApiVerifyControlSettings($control, $config->controlUser, $key));
        $identity = e2eProvisionStep('verify /api/me over WireGuard with persisted CA', fn () => nodeNewGatewayApiVerifyMe($control, $config->controlUser, $key, $settings['ca_pem_path']));

        expect($settings['gateway_url'])->toBe("https://{$gatewayIp}")
            ->and($settings['gateway_wg_ip'])->toBe('10.6.0.2')
            ->and($settings['ca_sha256'])->toBe($payload['success']['data']['gateway_trust']['ca_sha256'])
            ->and($settings['ca_pem_path'])->toBe($payload['success']['data']['gateway_trust']['ca_pem_path'])
            ->and($identity['success']['data']['self'])->toMatchArray([
                'name' => 'control-1',
                'role' => 'control',
                'status' => 'active',
                'addresses' => [
                    'wireguard' => '10.6.0.3',
                ],
            ])
            ->and($identity['success']['data']['gateway'])->toMatchArray([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'status' => 'active',
                'addresses' => [
                    'wireguard' => '10.6.0.2',
                ],
            ]);

        $rerun = e2eProvisionStep('rerun node:new gateway', fn () => E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            $command,
            timeoutSeconds: 1800,
        ));

        $rerunPayload = json_decode(trim($rerun->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rerunSettings = e2eProvisionStep('read idempotent gateway settings', fn () => nodeNewGatewayApiVerifyControlSettings($control, $config->controlUser, $key));
        $rerunIdentity = e2eProvisionStep('verify idempotent /api/me over WireGuard', fn () => nodeNewGatewayApiVerifyMe($control, $config->controlUser, $key, $rerunSettings['ca_pem_path']));

        expect($rerunPayload['success']['data']['result']['action'])->toBe('converged')
            ->and($rerunPayload['success']['data']['local_onboarding']['wireguard'])->toBe('already_installed')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_api'])->toBe('verified')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_trust'])->toBe('already_trusted')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_config'])->toBe('already_stored')
            ->and($rerunSettings)->toBe($settings)
            ->and($rerunIdentity)->toBe($identity);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

function nodeNewGatewayApiVerifyAuthorizeSsh(
    E2EInstance $control,
    E2EInstance $gateway,
    string $controlUser,
    string $bootstrapUser,
    SshKeyPair $key,
): void {
    $control->authorizeSsh($controlUser, $key);
    $gateway->authorizeSsh($bootstrapUser, $key);
    $control->copyFileToInstance($key->privateKeyPath, "/home/{$controlUser}/.ssh/id_ed25519");

    $result = $control->exec("chown {$controlUser}:{$controlUser} /home/{$controlUser}/.ssh/id_ed25519 && chmod 600 /home/{$controlUser}/.ssh/id_ed25519");

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
}

/**
 * @return array{gateway_url: string|null, gateway_wg_ip: string|null, ca_sha256: string|null, ca_pem_path: string}
 */
function nodeNewGatewayApiVerifyControlSettings(E2EInstance $control, string $controlUser, SshKeyPair $key): array
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
        "cd /home/{$controlUser}/orbit && php artisan tinker --execute=".escapeshellarg($php),
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function nodeNewGatewayApiVerifyMe(E2EInstance $control, string $controlUser, SshKeyPair $key, string $caPath): array
{
    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'curl --connect-timeout 5 --max-time 15 --cacert '.escapeshellarg($caPath).' -fsS https://10.6.0.2/api/me',
        timeoutSeconds: 30,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}
