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
})->group('e2e-topology-contract-operator_gateway', 'e2e-topology-contract-control-gateway');

it('satisfies the prepared control-gateway-dev topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdev);

    try {
        expectPreparedDevTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev', 'e2e-topology-contract-control-gateway-dev');

it('satisfies the prepared control-gateway-dev-ingress topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevIngress);

    try {
        expectPreparedDevTopology($topology, $config);
        expectPreparedIngressPlacement($topology);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev_ingress');

it('satisfies the prepared control-gateway-dev-websocket topology scaffold', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevWebsocket);

    try {
        expectPreparedDevServiceScaffold($topology, $config, ['websocket']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev_websocket');

it('satisfies the prepared control-gateway-dev-s3 topology scaffold', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevS3);

    try {
        expectPreparedDevServiceScaffold($topology, $config, ['s3']);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev_s3');

it('satisfies the prepared control-gateway-dev-ingress-websocket-s3 topology scaffold', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevIngressWebsocketS3);

    try {
        expectPreparedDevServiceScaffold($topology, $config, ['websocket', 's3']);
        expectPreparedIngressPlacement($topology);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev_ingress_websocket_s3');

it('satisfies the prepared control-gateway-dev-prod topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevAppprod);

    try {
        expectPreparedProdTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev_app-prod', 'e2e-topology-contract-control-gateway-dev-prod');

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

    $gatewayUrl = readPreparedClientGatewayUrl($control, $config->controlUser, $key);

    expect($gatewayUrl)->toBe(expectedPreparedGatewayUrl($topology));

    E2EGatewayApi::waitForGatewayApi($control, $config->controlUser, $key);
    expectPreparedGatewayCertificateKeysReadable($gateway, $key);

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

    expectPreparedAppNode($devNode, 'development', 'test', expectedPreparedGatewayEndpoint());
    expectPreparedDevDatabaseAndRedis($topology);
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

    expectPreparedAppNode($prodNode, 'production', null, expectedPreparedGatewayEndpoint());
}

/**
 * @param  list<string>  $futureRoles
 */
function expectPreparedDevServiceScaffold(E2ETopologyLease $topology, E2EConfig $config, array $futureRoles): void
{
    expectPreparedDevTopology($topology, $config);

    foreach ($futureRoles as $role) {
        $instance = $topology->instance($role);

        if ($instance === null) {
            throw new RuntimeException("Prepared topology did not return a {$role} placement handle.");
        }

        expectPreparedOrbitCli($instance, 'orbit', $topology->sshKeyPair());
    }
}

function expectPreparedIngressPlacement(E2ETopologyLease $topology): void
{
    $ingress = $topology->ingress();

    if ($ingress === null) {
        throw new RuntimeException('Prepared topology did not return an ingress handle.');
    }

    expectPreparedOrbitCli($ingress, 'orbit', $topology->sshKeyPair());
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

function expectPreparedDevDatabaseAndRedis(E2ETopologyLease $topology): void
{
    $gateway = $topology->gateway();

    if ($gateway === null) {
        throw new RuntimeException('Prepared dev topology did not return a gateway handle.');
    }

    $state = readPreparedDevServiceState($gateway);

    expect($state['roles'])->toContain('app-development')
        ->and($state['roles'])->toContain('database')
        ->and($state['redis_expected_state'])->toBe('running');
}

/**
 * @return array{roles: list<string>, redis_expected_state: string|null}
 */
function readPreparedDevServiceState(E2EInstance $gateway): array
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

echo json_encode([
    'roles' => $node->roleAssignments()
        ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
        ->orderBy('role')
        ->pluck('role')
        ->values()
        ->all(),
    'redis_expected_state' => \App\Models\NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'redis')
        ->value('expected_state'),
], JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::orbit(
        $gateway,
        preparedOrbitTinkerCommand('/home/orbit/orbit', $php),
        'Could not read prepared appdev service state',
    );

    /** @var array{roles: list<string>, redis_expected_state: string|null} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}

function expectPreparedGatewayCertificateKeysReadable(E2EInstance $gateway, SshKeyPair $key): void
{
    E2ECommand::ssh(
        $gateway,
        'orbit',
        $key,
        implode(' && ', [
            'test -r /home/orbit/orbit/storage/app/orbit/ca/root.key',
            '(test -r /home/orbit/orbit/storage/app/orbit/certs/gateway.key || test -r /home/orbit/orbit/storage/app/orbit/certs/10.6.0.2.key)',
        ]),
        timeoutSeconds: 60,
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
        'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg($php),
        'Could not read prepared local gateway node',
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function preparedOrbitTinkerCommand(string $orbitPath, string $php): string
{
    $encodedPhp = base64_encode($php);

    return 'cd '.escapeshellarg($orbitPath).' && orbit tinker --execute='.escapeshellarg("eval(base64_decode('{$encodedPhp}'));");
}

function readPreparedClientGatewayUrl(E2EInstance $control, string $controlUser, SshKeyPair $key): string
{
    $result = E2ECommand::ssh(
        $control,
        $controlUser,
        $key,
        'cd '.escapeshellarg("/home/{$controlUser}/orbit/apps/cli")." && grep -E '^ORBIT_GATEWAY_URL=' .env | tail -n 1 | cut -d= -f2-",
    );

    return trim($result->output());
}

/**
 * @param  array<string, mixed>  $node
 */
function expectedPreparedGatewayUrl(E2ETopologyLease $topology): string
{
    if (getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE') === 'dns-alias') {
        return 'http://gateway';
    }

    return 'http://'.$topology->gatewayApiIp();
}

function expectedPreparedGatewayEndpoint(): string
{
    if (getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE') === 'dns-alias') {
        return 'gateway';
    }

    return '10.6.0.2';
}

function expectPreparedAppNode(array $node, string $environment, ?string $tld, string $gatewayEndpoint): void
{
    expect($node['role'])->toBe('app')
        ->and($node['environment'])->toBe($environment)
        ->and($node['tld'])->toBe($tld)
        ->and($node['gateway_endpoint'])->toBe($gatewayEndpoint)
        ->and($node['user'])->toBe('orbit')
        ->and(is_string($node['wireguard_address']))->toBeTrue()
        ->and(str_starts_with((string) $node['wireguard_address'], '10.6.0.'))->toBeTrue();
}
