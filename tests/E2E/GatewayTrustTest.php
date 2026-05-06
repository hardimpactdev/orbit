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

it('trusts the configured gateway root CA from a provisioned control VM', function (): void {
    $config = E2EConfig::fromEnvironment();
    $provider = new IncusProvider($config);
    $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

    if (! $selection->available()) {
        $this->markTestSkipped($selection->message);
    }

    $run = E2ERun::start($provider, 'gateway-trust');
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
        E2EGatewayApi::start($gateway, 'gateway-trust');
        E2EGatewayApi::waitForGatewayApi($control, $config->controlUser, $key);

        gatewayTrustE2ESeedSettings($control, $config->controlUser, $key);

        $trust = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:trust --json",
            timeoutSeconds: 600,
        );

        $payload = json_decode(trim($trust->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $settings = gatewayTrustE2EControlSettings($control, $config->controlUser, $key);
        $remoteCa = gatewayTrustE2ERemoteCa($gateway);
        $trustStoreHash = gatewayTrustE2ETrustStoreHash($control, $config->controlUser, $key);
        $localNodeMirrorCount = gatewayTrustE2ELocalNodeMirrorCount($control, $config->controlUser, $key);

        expect($payload['success']['data']['gateway_trust']['gateway_url'])->toBe('https://10.6.0.2')
            ->and($payload['success']['data']['gateway_trust']['trusted'])->toBeTrue()
            ->and($payload['success']['data']['gateway_trust']['status'])->toBe('trusted')
            ->and($payload['success']['data']['gateway_trust']['ca_sha256'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($settings['gateway_url'])->toBe('https://10.6.0.2')
            ->and($settings['gateway_wg_ip'])->toBe('10.6.0.2')
            ->and($settings['ca_sha256'])->toBe($payload['success']['data']['gateway_trust']['ca_sha256'])
            ->and($settings['pem_sha256'])->toBe($settings['ca_sha256'])
            ->and(trim($settings['pem']))->toBe(trim($remoteCa))
            ->and($settings['trusted_at'])->toBeString()->not->toBeEmpty()
            ->and($trustStoreHash)->toBe($settings['ca_sha256'])
            ->and($localNodeMirrorCount)->toBe('0');

        $rerun = E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            "cd /home/{$config->controlUser}/orbit && orbit gateway:trust --json",
            timeoutSeconds: 600,
        );

        $rerunPayload = json_decode(trim($rerun->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $rerunSettings = gatewayTrustE2EControlSettings($control, $config->controlUser, $key);
        $rerunTrustStoreHash = gatewayTrustE2ETrustStoreHash($control, $config->controlUser, $key);

        expect($rerunPayload['success']['data']['gateway_trust']['status'])->toBe('already_trusted')
            ->and($rerunPayload['success']['data']['gateway_trust']['ca_sha256'])->toBe($settings['ca_sha256'])
            ->and($rerunSettings)->toBe($settings)
            ->and($rerunTrustStoreHash)->toBe($trustStoreHash);

        $passed = true;
    } finally {
        e2eProvisionCleanup($passed, run: $run);
        $bundle?->cleanup();
    }
});

function gatewayTrustE2ESeedSettings(E2EInstance $control, string $controlUser, SshKeyPair $key): void
{
    $php = <<<'PHP'
\App\Models\LocalGatewaySettings::current()->fill([
    'gateway_url' => 'https://10.6.0.2',
    'gateway_wg_ip' => '10.6.0.2',
    'ca_sha256' => null,
    'ca_pem_path' => null,
    'trusted_at' => null,
])->save();
PHP;

    E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'cd /home/'.$controlUser.'/orbit && php artisan tinker --execute='.escapeshellarg($php),
    );
}

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
function gatewayTrustE2EControlSettings(E2EInstance $control, string $controlUser, SshKeyPair $key): array
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
        'cd /home/'.$controlUser.'/orbit && php artisan tinker --execute='.escapeshellarg($php),
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function gatewayTrustE2ERemoteCa(E2EInstance $gateway): string
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

function gatewayTrustE2ETrustStoreHash(E2EInstance $control, string $controlUser, SshKeyPair $key): string
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

function gatewayTrustE2ELocalNodeMirrorCount(E2EInstance $control, string $controlUser, SshKeyPair $key): string
{
    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        "cd /home/{$controlUser}/orbit && php artisan tinker --execute='echo \\App\\Models\\Node::query()->count();'",
    );

    return trim($result->output());
}
