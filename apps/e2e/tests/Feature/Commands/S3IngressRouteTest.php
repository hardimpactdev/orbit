<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('flows public s3 ingress through router to seaweedfs backend and includes public endpoint in credentials', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $publicHost = "s3.{$suffix}.example.test";

    try {
        expect($topology->lease()->prodApp())->not->toBeNull()
            ->and($topology->lease()->devApp())->not->toBeNull()
            ->and($topology->lease()->gateway())->not->toBeNull()
            ->and($topology->lease()->agent())->toBeNull();

        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 's3-ingress-route');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        // Seed the database and obtain a snapshot of the service route.
        $serviceSnapshot = s3IngressServiceRouteSnapshot($topology);
        $serviceRoute = $serviceSnapshot['route'];
        $serviceConfig = $serviceRoute['config'];

        expect($serviceSnapshot['nodes']['app_prod_roles'])->toContain('ingress')
            ->and($serviceSnapshot['nodes']['app_dev_roles'])->toContain('s3');

        expect($serviceRoute)->toMatchArray([
            'domain' => 's3.orbit',
            'node' => 'gateway',
            'owner_type' => 'router',
            'kind' => 'proxy',
        ])
            ->and($serviceConfig['protocol'])->toBe('s3')
            ->and($serviceConfig['owner_name'])->toBe('seaweedfs')
            ->and($serviceConfig['upstreams'][0]['scheme'])->toBe('http')
            ->and($serviceConfig['upstreams'][0]['host'])->toBe('app-dev-1.s3.orbit')
            ->and($serviceConfig['upstreams'][0]['port'])->toBe(8333);

        // Run orbit s3:publish from the operator node.
        $publishResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit s3:publish %s --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
                escapeshellarg($publicHost),
            ),
            timeoutSeconds: 120,
        );

        expect($publishResult->successful())->toBeTrue();

        $publishPayload = json_decode(trim($publishResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        // The streaming command emits {"event":"complete","data":{"exit_code":0,"data":{"s3":{...},"meta":{...}}}}
        expect($publishPayload['event'])->toBe('complete');

        $publishedS3 = $publishPayload['data']['data']['s3'] ?? [];
        $publishedMeta = $publishPayload['data']['data']['meta'] ?? [];

        expect($publishedS3['node'])->toBe('app-dev-1')
            ->and($publishedS3['private_endpoint'])->toBe('https://s3.orbit')
            ->and($publishedS3['public_endpoints'])->toContain("https://{$publicHost}")
            ->and($publishedS3['backend_pool'])->toContain('http://app-dev-1.s3.orbit:8333')
            ->and($publishedMeta['host'])->toBe($publicHost)
            ->and($publishedMeta['action'])->toBe('published')
            ->and($publishedMeta['already_published'])->toBeFalse();

        // Snapshot the public ingress route after publishing.
        $ingressSnapshot = s3IngressPublicRouteSnapshot($topology, $publicHost);
        $publicRoute = $ingressSnapshot['public_route'];
        $publicConfig = $publicRoute['config'];

        expect($publicRoute)->toMatchArray([
            'domain' => $publicHost,
            'node' => 'app-prod-1',
            'owner_type' => 's3',
            'kind' => 'proxy',
        ])
            ->and($publicRoute['source_hash'])->toBe($publicRoute['expected_source_hash'])
            ->and($publicConfig['placement'])->toBe('ingress')
            ->and($publicConfig['owner_name'])->toBe('seaweedfs')
            ->and($publicConfig['protocol'])->toBe('s3')
            ->and($publicConfig['target']['value'])->toBe('https://s3.orbit')
            ->and($publicConfig['router_upstream']['node'])->toBe('gateway');

        // Assert the service route backend pool contains the app-dev node.
        $refreshedService = $ingressSnapshot['service_route'];
        $refreshedServiceConfig = $refreshedService['config'];

        expect($refreshedServiceConfig['upstreams'][0]['host'])->toBe('app-dev-1.s3.orbit')
            ->and($refreshedServiceConfig['upstreams'][0]['port'])->toBe(8333)
            ->and($refreshedServiceConfig['upstreams'][0]['scheme'])->toBe('http');

        // Assert the rendered ingress Caddy route contains the expected substrings.
        $renderedIngress = $ingressSnapshot['rendered_ingress_route'];

        expect($renderedIngress)
            ->toContain("{$publicHost} {")
            ->toContain('flush_interval -1');

        // Run orbit s3:credentials and assert public_endpoints.
        $credResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit s3:credentials --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        expect($credResult->successful())->toBeTrue();

        $credPayload = json_decode(trim($credResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $credentials = $credPayload['success']['data']['credentials'] ?? [];
        $credMeta = $credPayload['success']['meta'] ?? [];

        expect($credentials['private_endpoint'])->toBe('https://s3.orbit')
            ->and($credentials['region'])->toBe('orbit')
            ->and($credentials['access_key_id'])->toBe('TESTKEYID12345678901')
            ->and($credentials['secret_access_key'])->toBe('test-secret-access-key-e2e')
            ->and($credentials['bucket_endpoint_style'])->toBe('path')
            ->and($credentials['backend_pool'])->toContain('http://app-dev-1.s3.orbit:8333')
            ->and($credentials['public_endpoints'])->toContain("https://{$publicHost}")
            ->and($credMeta['tool'])->toBe('seaweedfs');
    } finally {
        s3IngressRouteCleanup($topology, $publicHost);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod');

it('proves SeaweedFS binds only to the WireGuard interface and is not exposed on the public-interface', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod, withGatewayApi: true);

    try {
        expect($topology->lease()->devApp())->not->toBeNull()
            ->and($topology->lease()->gateway())->not->toBeNull();

        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 's3-public-interface-bind');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $snapshot = s3RuntimeContainerBindSnapshot($topology);

        $wgAddress = $snapshot['wireguard_address'];
        $host = $snapshot['host'];
        $publishedPorts = $snapshot['published_ports'];
        $seaweedfsAddress = $snapshot['seaweedfs_address'];

        // The WireGuard address must be present and non-empty.
        expect($wgAddress)->not->toBeEmpty();

        // The port is published ONLY on the WireGuard IP — not on 0.0.0.0.
        expect($publishedPorts)->toBe(["{$wgAddress}:8333:8333"]);

        // SeaweedFS binds only to the WireGuard IP.
        expect($seaweedfsAddress)->toBe("{$wgAddress}:8333");

        // Neither published_ports nor seaweedfs_address may expose 0.0.0.0.
        expect(implode(',', $publishedPorts))->not->toContain('0.0.0.0')
            ->and($seaweedfsAddress)->not->toContain('0.0.0.0');

        // Every published-ports entry starts with the WireGuard IP.
        foreach ($publishedPorts as $entry) {
            expect($entry)->toStartWith("{$wgAddress}:");
        }

        // When the public host differs from the WireGuard address, neither
        // published_ports nor seaweedfs_address may reference the public interface.
        if ($host !== $wgAddress) {
            expect(implode(',', $publishedPorts))->not->toContain($host)
                ->and($seaweedfsAddress)->not->toContain($host);
        }
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod');

/**
 * Seed the gateway database with an active s3 role on app-dev-1 and render the
 * SeaweedFS runtime container, returning a snapshot of the bind posture.
 *
 * @return array{
 *     wireguard_address: string,
 *     host: string,
 *     published_ports: list<string>,
 *     seaweedfs_address: string|null,
 * }
 */
function s3RuntimeContainerBindSnapshot(E2ETopologyHarness $topology): array
{
    $php = <<<'PHP'
$node = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

\App\Models\NodeRoleAssignment::query()->updateOrCreate(
    [
        'node_id' => $node->id,
        'role' => 's3',
    ],
    [
        'status' => 'active',
        'settings' => ['data_path' => '/srv/orbit/s3/data'],
        'last_error' => null,
        'converged_at' => now(),
    ],
);

$assignment = $node->roleAssignments()
    ->where('role', 's3')
    ->where('status', 'active')
    ->firstOrFail();

$settings = \App\Data\Nodes\RoleSettings\S3RoleSettings::fromArray(
    is_array($assignment->settings) ? $assignment->settings : []
);

$container = app(\App\Services\S3\S3RuntimeContainerRenderer::class)->render($node, $settings);
$publishedPorts = $container->publishedPorts();
$publishedAddress = isset($publishedPorts[0])
    ? implode(':', array_slice(explode(':', $publishedPorts[0]), 0, 2))
    : null;

echo json_encode([
    'wireguard_address' => $node->wireguard_address,
    'host' => $node->host,
    'published_ports' => $publishedPorts,
    'seaweedfs_address' => $publishedAddress,
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && php apps/gateway/artisan tinker --execute=%s',
            escapeshellarg($topology->checkout('gateway')),
            escapeshellarg("eval(base64_decode('".base64_encode($php)."'));"),
        ),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Seed the gateway database with an active s3 role on app-dev-1 and a seaweedfs
 * tool row, sync the private s3.orbit service route, and return a snapshot of
 * the service route together with the active role assignments for the prod and
 * dev nodes.
 *
 * @return array{
 *     route: array{
 *         domain: string,
 *         node: string,
 *         owner_type: string,
 *         kind: string,
 *         config: array<string, mixed>,
 *         source_hash: string,
 *         expected_source_hash: string,
 *     },
 *     rendered_route: string,
 *     nodes: array{
 *         app_prod_roles: list<string>,
 *         app_dev_roles: list<string>,
 *     },
 * }
 */
function s3IngressServiceRouteSnapshot(E2ETopologyHarness $topology): array
{
    $php = <<<'PHP'
$appDevNode = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();
$appProdNode = \App\Models\Node::query()->where('name', 'app-prod-1')->firstOrFail();

\App\Models\NodeRoleAssignment::query()->updateOrCreate(
    [
        'node_id' => $appDevNode->id,
        'role' => 's3',
    ],
    [
        'status' => 'active',
        'settings' => ['data_path' => '/srv/orbit/s3/data'],
        'last_error' => null,
        'converged_at' => now(),
    ],
);

\App\Models\NodeTool::query()->updateOrCreate(
    [
        'node_id' => $appDevNode->id,
        'name' => 'seaweedfs',
    ],
    [
        'expected_state' => 'running',
        'config' => [
            'backend_host' => 'app-dev-1.s3.orbit',
            'public_hosts' => [],
        ],
        'credentials' => [
            'fields' => [
                'access_key_id' => 'TESTKEYID12345678901',
                'secret_access_key' => 'test-secret-access-key-e2e',
                'region' => 'orbit',
                'endpoint' => 'https://s3.orbit',
                'bucket_style' => 'path',
            ],
        ],
    ],
);

$route = app(\App\Services\S3\S3RouteRegistrar::class)->syncServiceRoute()->load('node');
$renderer = app(\App\Services\Proxy\ProxyRouteRenderer::class);

echo json_encode([
    'route' => [
        'domain' => $route->domain,
        'node' => $route->node->name,
        'owner_type' => $route->owner_type,
        'kind' => $route->kind,
        'config' => $route->config,
        'source_hash' => $route->source_hash,
        'expected_source_hash' => $renderer->sourceHash($route),
    ],
    'rendered_route' => $renderer->render($route),
    'nodes' => [
        'app_prod_roles' => $appProdNode->roleAssignments()
            ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->pluck('role')
            ->values()
            ->all(),
        'app_dev_roles' => $appDevNode->roleAssignments()
            ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->pluck('role')
            ->values()
            ->all(),
    ],
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && php apps/gateway/artisan tinker --execute=%s',
            escapeshellarg($topology->checkout('gateway')),
            escapeshellarg("eval(base64_decode('".base64_encode($php)."'));"),
        ),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Snapshot the public ingress route and the service route after s3:publish.
 *
 * @return array{
 *     public_route: array{
 *         domain: string,
 *         node: string,
 *         owner_type: string,
 *         kind: string,
 *         config: array<string, mixed>,
 *         source_hash: string,
 *         expected_source_hash: string,
 *     },
 *     service_route: array{
 *         domain: string,
 *         node: string,
 *         owner_type: string,
 *         kind: string,
 *         config: array<string, mixed>,
 *         source_hash: string,
 *         expected_source_hash: string,
 *     },
 *     rendered_ingress_route: string,
 * }
 */
function s3IngressPublicRouteSnapshot(E2ETopologyHarness $topology, string $publicHost): array
{
    $php = <<<'PHP'
$publicHost = __PUBLIC_HOST__;
$publicRoute = \App\Models\ProxyRoute::query()
    ->with('node')
    ->where('domain', $publicHost)
    ->firstOrFail();

$serviceRoute = \App\Models\ProxyRoute::query()
    ->with('node')
    ->where('domain', \App\Services\S3\S3RouteRegistrar::ServiceDomain)
    ->firstOrFail();

$renderer = app(\App\Services\Proxy\ProxyRouteRenderer::class);

echo json_encode([
    'public_route' => [
        'domain' => $publicRoute->domain,
        'node' => $publicRoute->node->name,
        'owner_type' => $publicRoute->owner_type,
        'kind' => $publicRoute->kind,
        'config' => $publicRoute->config,
        'source_hash' => $publicRoute->source_hash,
        'expected_source_hash' => $renderer->sourceHash($publicRoute),
    ],
    'service_route' => [
        'domain' => $serviceRoute->domain,
        'node' => $serviceRoute->node->name,
        'owner_type' => $serviceRoute->owner_type,
        'kind' => $serviceRoute->kind,
        'config' => $serviceRoute->config,
        'source_hash' => $serviceRoute->source_hash,
        'expected_source_hash' => $renderer->sourceHash($serviceRoute),
    ],
    'rendered_ingress_route' => $renderer->render($publicRoute),
], JSON_THROW_ON_ERROR);
PHP;

    $php = strtr($php, [
        '__PUBLIC_HOST__' => var_export($publicHost, true),
    ]);

    $result = $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && php apps/gateway/artisan tinker --execute=%s',
            escapeshellarg($topology->checkout('gateway')),
            escapeshellarg("eval(base64_decode('".base64_encode($php)."'));"),
        ),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

function s3IngressRouteCleanup(E2ETopologyHarness $topology, string $publicHost): void
{
    if ($topology->checkouts() === []) {
        return;
    }

    $php = <<<'PHP'
$publicHost = __PUBLIC_HOST__;

\App\Models\ProxyRoute::query()
    ->where('domain', $publicHost)
    ->delete();
PHP;

    $php = strtr($php, [
        '__PUBLIC_HOST__' => var_export($publicHost, true),
    ]);

    $topology->ssh(
        'gateway',
        sprintf(
            'cd %s && php apps/gateway/artisan tinker --execute=%s',
            escapeshellarg($topology->checkout('gateway')),
            escapeshellarg("eval(base64_decode('".base64_encode($php)."'));"),
        ),
        timeoutSeconds: 120,
        allowFailure: true,
    );
}
