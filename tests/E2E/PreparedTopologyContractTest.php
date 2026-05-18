<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyUnavailable;
use App\E2E\Support\SshKeyPair;

pest()->group('e2e-topology-contract');

it('satisfies the prepared control topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::Operator);

    try {
        expectPreparedControlTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator', 'e2e-topology-contract-control');

it('satisfies the prepared control-gateway topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGateway);

    try {
        expectPreparedGatewayTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator-gateway', 'e2e-topology-contract-control-gateway');

it('satisfies the prepared control-gateway-dev topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdev);

    try {
        expectPreparedDevTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator-gateway-appdev', 'e2e-topology-contract-control-gateway-dev');

it('satisfies the prepared control-gateway-dev-prod topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevAppprod);

    try {
        expectPreparedProdTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator-gateway-appdev-appprod', 'e2e-topology-contract-control-gateway-dev-prod');

function requirePreparedTopologyOrSkip(E2ETopologyKind $kind): E2ETopologyLease
{
    try {
        return E2ETopologyFactory::fromEnvironment()->withGatewayApi()->require($kind);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }
}

function expectPreparedControlTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    $control = $topology->control();
    $key = $topology->sshKeyPair();

    expectPreparedOrbitCli($control, $config->controlUser, $key);

    $controlNode = readPreparedNodeFromControl($control, $config->controlUser, $key, 'control-1');

    expect($controlNode)->toBeNull();
}

function expectPreparedGatewayTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedControlTopology($topology, $config);

    $control = $topology->control();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($gateway === null) {
        throw new RuntimeException('Prepared control-gateway topology did not return a gateway handle.');
    }

    expectPreparedOrbitCli($gateway, 'orbit', $key);

    $gatewaySettings = readPreparedGatewaySettings($control, $config->controlUser, $key);

    expect($gatewaySettings['gateway_url'])->toBe('https://10.6.0.2')
        ->and($gatewaySettings['gateway_wg_ip'])->toBe('10.6.0.2')
        ->and($gatewaySettings['ca_pem_path'])->toContain('storage/app/orbit/gateway-ca/orbit.crt');

    E2EGatewayApi::waitForGatewayApi($control, $config->controlUser, $key);

    $gatewayNode = readPreparedLocalGatewayNode($gateway);
    $controlOnGateway = E2EGatewayApi::getNode($gateway, 'control-1');

    expect($gatewayNode['role'])->toBe('gateway')
        ->and($gatewayNode['wireguard_address'])->toBe('10.6.0.2');

    expect($controlOnGateway['role'])->toBe('control')
        ->and($controlOnGateway['wireguard_address'])->toBe('10.6.0.3');
}

function expectPreparedDevTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedGatewayTopology($topology, $config);

    $dev = $topology->devApp();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($dev === null || $gateway === null) {
        throw new RuntimeException('Prepared control-gateway-dev topology did not return gateway and dev handles.');
    }

    expectPreparedOrbitCli($dev, 'orbit', $key);

    $devNode = E2EGatewayApi::getNode($gateway, 'app-dev-1');

    expectPreparedAppNode($devNode, 'development', 'test');
}

function expectPreparedProdTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedDevTopology($topology, $config);

    $prod = $topology->prodApp();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($prod === null || $gateway === null) {
        throw new RuntimeException('Prepared control-gateway-dev-prod topology did not return gateway and prod handles.');
    }

    expectPreparedOrbitCli($prod, 'orbit', $key);

    $prodNode = E2EGatewayApi::getNode($gateway, 'app-prod-1');

    expectPreparedAppNode($prodNode, 'production', null);
}

function expectPreparedOrbitCli(E2EInstance $instance, string $user, SshKeyPair $key): void
{
    $orbitPath = "/home/{$user}/orbit";

    E2ECommand::ssh(
        $instance,
        $user,
        $key,
        'cd '.escapeshellarg($orbitPath).' && test -f artisan && orbit --version >/dev/null',
    );
}

/**
 * @return array<string, mixed>
 */
function readPreparedLocalGatewayNode(E2EInstance $gateway): array
{
    $php = <<<'PHP'
echo json_encode(\App\Models\Node::query()
    ->where('role', 'gateway')
    ->firstOrFail()
    ->only([
        'name',
        'role',
        'environment',
        'tld',
        'host',
        'wireguard_address',
        'gateway_endpoint',
                'user',
            ]), JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::orbit(
        $gateway,
        'cd /home/orbit/orbit && php artisan tinker --execute='.escapeshellarg($php),
        'Could not read prepared local gateway node',
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
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
        'user',
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
        ->and(is_string($node['wireguard_address']))->toBeTrue()
        ->and(str_starts_with((string) $node['wireguard_address'], '10.6.0.'))->toBeTrue();
}
