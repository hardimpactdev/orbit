<?php

declare(strict_types=1);

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

it('NodeNewGatewayCaVerify proves first-gateway CA trust from blank VMs', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Blank);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'node-new-gateway-ca-verify');
    $bundle = null;
    $passed = false;

    try {
        $bundle = E2EProvisioningBundle::stage($provider);
        $key = $run->createSshKeyPair();
        $control = e2eProvisionControlFromBlank($provider, $run, $bundle, $config, $key);
        [$gateway, $payload] = e2eProvisionGatewayThroughNodeNew($provider, $run, $config, $control, $key, useWireGuardGatewayUrl: false);

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        expect($controlIp)->not->toBe($gatewayIp);

        $command = "cd /home/{$config->controlUser}/orbit && php artisan node:new gateway-1 --role=gateway --host={$gatewayIp} --user={$config->bootstrapUser} --control-name=control-1 --json";
        expect($payload['success']['data']['result']['action'])->toBe('created')
            ->and($payload['success']['data']['local_onboarding']['gateway_trust'])->toBe('trusted')
            ->and($payload['success']['data']['local_onboarding']['gateway_config'])->toBe('stored')
            ->and($payload['success']['data']['gateway_trust']['trusted'])->toBeTrue()
            ->and($payload['success']['data']['gateway_trust']['status'])->toBe('trusted')
            ->and($payload['success']['data']['gateway_trust']['ca_sha256'])->toMatch('/^[a-f0-9]{64}$/');

        $settings = e2eProvisionStep('assert persisted CA settings', fn () => nodeNewGatewayCaVerifyControlSettings($control, $config->controlUser, $key));
        $remoteCa = e2eProvisionStep('read gateway CA certificate', fn () => nodeNewGatewayCaVerifyRemoteCa($gateway));
        $trustStoreHash = e2eProvisionStep('assert local trust store certificate', fn () => nodeNewGatewayCaVerifyTrustStoreHash($control, $config->controlUser, $key));

        expect($settings['gateway_url'])->toBe("https://{$gatewayIp}")
            ->and($settings['gateway_wg_ip'])->toBe('10.6.0.2')
            ->and($settings['ca_pem_path'])->toBe("/home/{$config->controlUser}/orbit/storage/app/orbit/gateway-ca/orbit.crt")
            ->and($settings['ca_sha256'])->toBe($payload['success']['data']['gateway_trust']['ca_sha256'])
            ->and($settings['pem_sha256'])->toBe($settings['ca_sha256'])
            ->and($settings['pem'])->toBe($remoteCa)
            ->and($settings['trusted_at'])->toBeString()->not->toBeEmpty()
            ->and($trustStoreHash)->toBe($settings['ca_sha256'])
            ->and(openssl_x509_parse($settings['pem']))->not->toBeFalse();

        $rerun = e2eProvisionStep('rerun node:new gateway', fn () => E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            $command,
            timeoutSeconds: 1800,
        ));

        $rerunPayload = json_decode(trim($rerun->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rerunSettings = e2eProvisionStep('assert CA trust idempotence', fn () => nodeNewGatewayCaVerifyControlSettings($control, $config->controlUser, $key));
        $rerunTrustStoreHash = e2eProvisionStep('assert trust store idempotence', fn () => nodeNewGatewayCaVerifyTrustStoreHash($control, $config->controlUser, $key));

        expect($rerunPayload['success']['data']['result']['action'])->toBe('converged')
            ->and($rerunPayload['success']['data']['local_onboarding']['wireguard'])->toBe('already_installed')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_trust'])->toBe('already_trusted')
            ->and($rerunPayload['success']['data']['local_onboarding']['gateway_config'])->toBe('already_stored')
            ->and($rerunPayload['success']['data']['gateway_trust']['ca_sha256'])->toBe($settings['ca_sha256'])
            ->and($rerunPayload['success']['data']['gateway_trust']['ca_pem_path'])->toBe($settings['ca_pem_path'])
            ->and($rerunSettings)->toBe($settings)
            ->and($rerunTrustStoreHash)->toBe($trustStoreHash);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

/**
 * @return array{
 *     gateway_url: string|null,
 *     gateway_wg_ip: string|null,
 *     ca_sha256: string|null,
 *     ca_pem_path: string|null,
 *     pem_sha256: string|null,
 *     pem: string,
 *     trusted_at: string|null
 * }
 */
function nodeNewGatewayCaVerifyControlSettings(E2EInstance $control, string $controlUser, SshKeyPair $key): array
{
    $php = <<<'PHP'
$settings = \App\Models\LocalGatewaySettings::current();
$pem = is_string($settings->ca_pem_path) && is_file($settings->ca_pem_path)
    ? file_get_contents($settings->ca_pem_path)
    : '';

echo json_encode([
    'gateway_url' => $settings->gateway_url,
    'gateway_wg_ip' => $settings->gateway_wg_ip,
    'ca_sha256' => $settings->ca_sha256,
    'ca_pem_path' => $settings->ca_pem_path,
    'pem_sha256' => is_string($pem) && $pem !== '' ? hash('sha256', $pem) : null,
    'pem' => $pem,
    'trusted_at' => $settings->trusted_at?->toISOString(),
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

function nodeNewGatewayCaVerifyRemoteCa(E2EInstance $gateway): string
{
    $php = <<<'PHP'
echo app(\App\Services\Ca\OrbitCaService::class)->rootCert();
PHP;

    $result = E2ECommand::orbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
        'Could not read gateway root CA',
    );

    return trim($result->output());
}

function nodeNewGatewayCaVerifyTrustStoreHash(E2EInstance $control, string $controlUser, SshKeyPair $key): string
{
    $php = <<<'PHP'
echo hash_file('sha256', '/usr/local/share/ca-certificates/orbit-gateway-ca-orbit.crt');
PHP;

    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'test -f /usr/local/share/ca-certificates/orbit-gateway-ca-orbit.crt && php -r '.escapeshellarg($php),
    );

    return trim($result->output());
}
