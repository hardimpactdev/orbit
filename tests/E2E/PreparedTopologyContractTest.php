<?php

declare(strict_types=1);

use Tests\E2E\Support\E2ECommand;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2EGatewayApi;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ETopologyFactory;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\SshKeyPair;

pest()->group('e2e-feature');

it('satisfies the prepared full topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = E2ETopologyFactory::fromEnvironment()->require(E2ETopologyKind::ControlGatewayDevProd);

    try {
        $control = $topology->control();
        $gateway = $topology->gateway();
        $dev = $topology->devApp();
        $prod = $topology->prodApp();
        $key = $topology->sshKeyPair();

        if ($gateway === null || $dev === null || $prod === null) {
            throw new RuntimeException('Full prepared topology did not return gateway, dev, and prod handles.');
        }

        expectPreparedOrbitCli($control, $config->controlUser, $key);
        expectPreparedOrbitCli($gateway, 'orbit', $key);
        expectPreparedOrbitCli($dev, 'orbit', $key);
        expectPreparedOrbitCli($prod, 'orbit', $key);

        $controlNode = readPreparedNodeFromControl($control, $config->controlUser, $key, 'control-1');

        expect($controlNode)->not->toBeNull()
            ->and($controlNode['role'])->toBe('control')
            ->and($controlNode['is_local'])->toBeTrue();

        $gatewaySettings = readPreparedGatewaySettings($control, $config->controlUser, $key);

        expect($gatewaySettings['gateway_url'])->toBe('https://10.6.0.2')
            ->and($gatewaySettings['gateway_wg_ip'])->toBe('10.6.0.2')
            ->and($gatewaySettings['ca_pem_path'])->toContain('storage/app/orbit/gateway-ca/orbit.crt');

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $key,
            'curl --connect-timeout 2 --max-time 5 -fsS http://10.6.0.2/api/ca/root >/dev/null && curl --connect-timeout 2 --max-time 5 -fsSk https://10.6.0.2/api/me >/dev/null',
            timeoutSeconds: 15,
        );

        $gatewayNode = E2EGatewayApi::getNode($gateway, 'gateway');
        $controlOnGateway = E2EGatewayApi::getNode($gateway, 'control-1');
        $devNode = E2EGatewayApi::getNode($gateway, 'app-dev-1');
        $prodNode = E2EGatewayApi::getNode($gateway, 'app-prod-1');

        expect($gatewayNode['role'])->toBe('gateway')
            ->and($gatewayNode['wireguard_address'])->toBe('10.6.0.2')
            ->and((bool) $gatewayNode['is_local'])->toBeTrue();

        expect($controlOnGateway['role'])->toBe('control')
            ->and($controlOnGateway['wireguard_address'])->toBe('10.6.0.3')
            ->and((bool) $controlOnGateway['is_local'])->toBeFalse();

        expectPreparedAppNode($devNode, 'development', 'test');
        expectPreparedAppNode($prodNode, 'production', null);
    } finally {
        $topology->cleanup();
    }
});

function expectPreparedOrbitCli(E2EInstance $instance, string $user, SshKeyPair $key): void
{
    $orbitPath = "/home/{$user}/orbit";

    E2ECommand::ssh(
        $instance,
        $user,
        $key,
        'cd '.escapeshellarg($orbitPath).' && test -f artisan && orbit --version | grep -F Orbit',
    );
}

/**
 * @return array<string, mixed>|null
 */
function readPreparedNodeFromControl(E2EInstance $control, string $controlUser, SshKeyPair $key, string $name): ?array
{
    $nameValue = var_export($name, true);

    $php = <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', {$nameValue})->first();
echo json_encode(\$node?->only([
    'name',
    'role',
    'environment',
    'tld',
    'host',
    'wireguard_address',
    'gateway_endpoint',
    'ssh_user',
    'user',
    'is_local',
]), JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'cd '.escapeshellarg("/home/{$controlUser}/orbit").' && php artisan tinker --execute='.escapeshellarg($php),
    );

    /** @var array<string, mixed>|null $node */
    $node = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $node;
}

/**
 * @return array{gateway_url: string|null, gateway_wg_ip: string|null, ca_pem_path: string|null}
 */
function readPreparedGatewaySettings(E2EInstance $control, string $controlUser, SshKeyPair $key): array
{
    $php = <<<'PHP'
$settings = \App\Models\LocalGatewaySettings::current();
echo json_encode([
    'gateway_url' => $settings->gateway_url,
    'gateway_wg_ip' => $settings->gateway_wg_ip,
    'ca_pem_path' => $settings->ca_pem_path,
], JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'cd '.escapeshellarg("/home/{$controlUser}/orbit").' && php artisan tinker --execute='.escapeshellarg($php),
    );

    /** @var array{gateway_url: string|null, gateway_wg_ip: string|null, ca_pem_path: string|null} $settings */
    $settings = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $settings;
}

/**
 * @param  array<string, mixed>  $node
 */
function expectPreparedAppNode(array $node, string $environment, ?string $tld): void
{
    expect($node['role'])->toBe('app')
        ->and($node['environment'])->toBe($environment)
        ->and($node['tld'])->toBe($tld)
        ->and($node['gateway_endpoint'])->toBe('10.6.0.2')
        ->and($node['user'])->toBe('orbit')
        ->and((bool) $node['is_local'])->toBeFalse()
        ->and(is_string($node['wireguard_address']))->toBeTrue()
        ->and(str_starts_with((string) $node['wireguard_address'], '10.6.0.'))->toBeTrue();
}
