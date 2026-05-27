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

it('satisfies the prepared operator topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::Operator);

    try {
        expectPreparedOperatorTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator', 'e2e-topology-contract-operator');

it('satisfies the prepared operator-gateway topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGateway);

    try {
        expectPreparedGatewayTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway', 'e2e-topology-contract-operator-gateway');

it('satisfies the prepared operator-gateway-dev topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdev);

    try {
        expectPreparedDevTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev', 'e2e-topology-contract-operator-gateway-dev');

it('satisfies the prepared operator-gateway-dev-prod topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppdevAppprod);

    try {
        expectPreparedProdTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-dev_app-prod', 'e2e-topology-contract-operator-gateway-dev-prod');

it('satisfies the prepared operator-gateway-agent topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAgent);

    try {
        expectPreparedAgentTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_agent');

it('satisfies the prepared operator-gateway-prod-ingress topology contract', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = requirePreparedTopologyOrSkip(E2ETopologyKind::OperatorGatewayAppprodIngress);

    try {
        expectPreparedProdIngressTopology($topology, $config);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-topology-contract-operator_gateway_app-prod_ingress');

function requirePreparedTopologyOrSkip(E2ETopologyKind $kind): E2ETopologyLease
{
    try {
        return E2ETopologyFactory::fromEnvironment()->withGatewayApi()->require($kind);
    } catch (E2ETopologyUnavailable $exception) {
        test()->markTestSkipped($exception->getMessage());
    }
}

function expectPreparedOperatorTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    $operator = $topology->operator();
    $key = $topology->sshKeyPair();

    expectPreparedOrbitCli($operator, $config->operatorUser, $key);
}

function expectPreparedGatewayTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedOperatorTopology($topology, $config);

    $operator = $topology->operator();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($gateway === null) {
        throw new RuntimeException('Prepared operator-gateway topology did not return a gateway handle.');
    }

    expectPreparedOrbitCli($gateway, 'orbit', $key);

    $gatewayUrl = readPreparedClientGatewayUrl($operator, $config->operatorUser, $key);

    expect($gatewayUrl)->toBe(expectedPreparedGatewayUrl($topology));

    E2EGatewayApi::waitForGatewayApi($operator, $config->operatorUser, $key, expectedPreparedGatewayApiHost($topology));
    expectPreparedGatewayCertificateKeysReadable($gateway, $key);

    $gatewayNode = readPreparedLocalGatewayNode($gateway);
    $operatorOnGateway = E2EGatewayApi::getNode($gateway, 'operator-1');

    expect($gatewayNode['role'])->toBe('gateway')
        ->and($gatewayNode['wireguard_address'])->toBe('10.6.0.2');

    expect($operatorOnGateway['role'])->toBe('operator')
        ->and($operatorOnGateway['wireguard_address'])->toBe('10.6.0.3');
}

function expectPreparedDevTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedGatewayTopology($topology, $config);

    $dev = $topology->devApp();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($dev === null || $gateway === null) {
        throw new RuntimeException('Prepared operator-gateway-dev topology did not return gateway and dev handles.');
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
        throw new RuntimeException('Prepared operator-gateway-dev-prod topology did not return gateway and prod handles.');
    }

    expectPreparedOrbitCli($prod, 'orbit', $key);

    $prodNode = E2EGatewayApi::getNode($gateway, 'app-prod-1');

    expectPreparedAppNode($prodNode, 'production', null, expectedPreparedGatewayEndpoint());
}

function expectPreparedAgentTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedGatewayTopology($topology, $config);

    $agent = $topology->agent();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($agent === null || $gateway === null) {
        throw new RuntimeException('Prepared operator-gateway-agent topology did not return gateway and agent handles.');
    }

    expect($topology->devApp())->toBeNull()
        ->and($topology->prodApp())->toBeNull();

    expectPreparedOrbitCli($agent, 'orbit', $key);

    $agentNode = E2EGatewayApi::getNode($gateway, 'agent-1');
    $state = readPreparedAgentState($gateway);

    expect($agentNode['tld'])->toBe('agent')
        ->and($agentNode['gateway_endpoint'])->toBe(expectedPreparedGatewayEndpoint())
        ->and($agentNode['user'])->toBe('orbit')
        ->and($agentNode['wireguard_address'])->toBe('10.6.0.6')
        ->and($state['roles'])->toContain('agent')
        ->and($state['node_names'])->toBe(['agent-1', 'gateway', 'operator-1']);
}

function expectPreparedProdIngressTopology(E2ETopologyLease $topology, E2EConfig $config): void
{
    expectPreparedGatewayTopology($topology, $config);

    $prod = $topology->prodApp();
    $ingress = $topology->ingress();
    $gateway = $topology->gateway();
    $key = $topology->sshKeyPair();

    if ($prod === null || $ingress === null || $gateway === null) {
        throw new RuntimeException('Prepared operator-gateway-prod-ingress topology did not return gateway, prod, and ingress handles.');
    }

    expect($topology->devApp())->toBeNull()
        ->and($topology->agent())->toBeNull()
        ->and($ingress->name())->toBe($prod->name());

    expectPreparedOrbitCli($prod, 'orbit', $key);

    $prodNode = E2EGatewayApi::getNode($gateway, 'app-prod-1');
    $state = readPreparedProdIngressState($gateway);

    expectPreparedAppNode($prodNode, 'production', null, expectedPreparedGatewayEndpoint());

    expect($prodNode['wireguard_address'])->toBe('10.6.0.5')
        ->and($state['roles'])->toContain('app-production')
        ->and($state['roles'])->toContain('ingress')
        ->and($state['app_production_ingress_node'])->toBe('app-prod-1')
        ->and($state['node_names'])->toBe(['app-prod-1', 'gateway', 'operator-1']);
}

function expectPreparedOrbitCli(E2EInstance $instance, string $user, SshKeyPair $key): void
{
    $orbitPath = "/home/{$user}/orbit";

    $result = E2ECommand::ssh(
        $instance,
        $user,
        $key,
        'cd '.escapeshellarg($orbitPath).' && test -f apps/gateway/artisan && orbit --version',
    );

    expect(trim($result->output()))->not->toBe('');
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

/**
 * @return array{roles: list<string>, node_names: list<string>}
 */
function readPreparedAgentState(E2EInstance $gateway): array
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'agent-1')->firstOrFail();

echo json_encode([
    'roles' => $node->roleAssignments()
        ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
        ->orderBy('role')
        ->pluck('role')
        ->values()
        ->all(),
    'node_names' => \App\Models\Node::query()->orderBy('name')->pluck('name')->values()->all(),
], JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::orbit(
        $gateway,
        preparedOrbitTinkerCommand('/home/orbit/orbit', $php),
        'Could not read prepared agent state',
    );

    /** @var array{roles: list<string>, node_names: list<string>} $state */
    $state = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

    return $state;
}

/**
 * @return array{roles: list<string>, app_production_ingress_node: string|null, node_names: list<string>}
 */
function readPreparedProdIngressState(E2EInstance $gateway): array
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-prod-1')->firstOrFail();
$assignments = $node->roleAssignments()
    ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
    ->orderBy('role')
    ->get(['role', 'settings']);

$appProduction = $assignments->firstWhere('role', \App\Enums\Nodes\NodeRoleName::AppProduction->value);
$appProductionSettings = $appProduction === null ? [] : ($appProduction->settings ?? []);
$ingressNodeId = $appProductionSettings['ingress_node_id'] ?? null;
$ingressNodeName = null;

if ($ingressNodeId !== null) {
    $ingressNodeName = \App\Models\Node::query()->whereKey($ingressNodeId)->value('name');
}

echo json_encode([
    'roles' => $assignments->pluck('role')->values()->all(),
    'app_production_ingress_node' => $ingressNodeName,
    'node_names' => \App\Models\Node::query()->orderBy('name')->pluck('name')->values()->all(),
], JSON_THROW_ON_ERROR);
PHP;

    $result = E2ECommand::orbit(
        $gateway,
        preparedOrbitTinkerCommand('/home/orbit/orbit', $php),
        'Could not read prepared app production ingress state',
    );

    /** @var array{roles: list<string>, app_production_ingress_node: string|null, node_names: list<string>} $state */
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
            'test -r /home/orbit/orbit/apps/gateway/storage/app/orbit/ca/root.key',
            '(test -r /home/orbit/orbit/apps/gateway/storage/app/orbit/certs/gateway.key || test -r /home/orbit/orbit/apps/gateway/storage/app/orbit/certs/10.6.0.2.key)',
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

function readPreparedClientGatewayUrl(E2EInstance $operator, string $operatorUser, SshKeyPair $key): string
{
    $result = E2ECommand::ssh(
        $operator,
        $operatorUser,
        $key,
        'cd '.escapeshellarg("/home/{$operatorUser}/orbit/apps/cli")." && grep -E '^ORBIT_GATEWAY_URL=' .env | tail -n 1 | cut -d= -f2-",
    );

    return trim($result->output());
}

/**
 * @param  array<string, mixed>  $node
 */
function expectedPreparedGatewayUrl(E2ETopologyLease $topology): string
{
    if (getenv('ORBIT_E2E_TOPOLOGY_PROVIDER') === 'docker') {
        return 'http://gateway';
    }

    return 'http://'.$topology->gatewayApiIp();
}

function expectedPreparedGatewayApiHost(E2ETopologyLease $topology): string
{
    if (getenv('ORBIT_E2E_TOPOLOGY_PROVIDER') === 'docker') {
        return 'gateway';
    }

    return $topology->gatewayApiIp();
}

function expectedPreparedGatewayEndpoint(): string
{
    if (getenv('ORBIT_E2E_TOPOLOGY_PROVIDER') === 'docker') {
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
