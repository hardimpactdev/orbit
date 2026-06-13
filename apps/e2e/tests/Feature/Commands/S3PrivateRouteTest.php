<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('routes the private s3 service to a rustfs backend through the router and returns correct credentials', function (): void {
    $config = E2EConfig::fromEnvironment();
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

    try {
        expect($topology->lease()->prodApp())->toBeNull()
            ->and($topology->lease()->agent())->toBeNull()
            ->and($topology->lease()->devApp())->not->toBeNull()
            ->and($topology->lease()->gateway())->not->toBeNull();

        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);
        $gatewayApiIp = $topology->lease()->gatewayApiIp();

        e2eRestartGatewayApi($topology, 's3-private-route');
        E2EGatewayApi::waitForGatewayApi(
            $topology->instance('operator'),
            $config->operatorUser,
            $topology->lease()->sshKeyPair(),
            gatewayIp: $gatewayApiIp,
        );
        e2eGrantNodeAccess($topology);

        $snapshot = s3PrivateRouteSnapshot($topology);
        $route = $snapshot['route'];
        $config = $route['config'];
        $renderedRoute = $snapshot['rendered_route'];

        expect($route)->toMatchArray([
            'domain' => 's3.orbit',
            'node' => 'gateway',
            'owner_type' => 'router',
            'kind' => 'proxy',
        ])
            ->and($snapshot['proxy_route_count'])->toBe(1)
            ->and($route['source_hash'])->toBe($route['expected_source_hash'])
            ->and($config['protocol'])->toBe('s3')
            ->and($config['owner_name'])->toBe('rustfs')
            ->and($config['upstreams'][0]['scheme'])->toBe('http')
            ->and($config['upstreams'][0]['host'])->toBe('app-dev-1.s3.orbit')
            ->and($config['upstreams'][0]['port'])->toBe(9000)
            ->and($config['target']['value'])->toBe('http://app-dev-1.s3.orbit:9000');

        expect($renderedRoute)
            ->toContain('s3.orbit {')
            ->toContain('reverse_proxy http://app-dev-1.s3.orbit:9000 {')
            ->toContain('flush_interval -1');

        $credResult = $topology->ssh(
            'operator',
            sprintf(
                'cd %s && orbit s3:credentials --node=app-dev-1 --json',
                escapeshellarg($topology->checkout('operator')),
            ),
            timeoutSeconds: 120,
        );

        $payload = json_decode(trim($credResult->output()), associative: true, flags: JSON_THROW_ON_ERROR);
        $credentials = $payload['success']['data']['credentials'] ?? [];
        $meta = $payload['success']['meta'] ?? [];

        expect($credResult->successful())->toBeTrue()
            ->and($credentials['private_endpoint'])->toBe('https://s3.orbit')
            ->and($credentials['region'])->toBe('orbit')
            ->and($credentials['access_key_id'])->toBe('TESTKEYID12345678901')
            ->and($credentials['secret_access_key'])->toBe('test-secret-access-key-e2e')
            ->and($credentials['bucket_endpoint_style'])->toBe('path')
            ->and($credentials['backend_pool'])->toContain('http://app-dev-1.s3.orbit:9000')
            ->and($meta['tool'])->toBe('rustfs');
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev');

/**
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
 *     proxy_route_count: int,
 * }
 */
function s3PrivateRouteSnapshot(E2ETopologyHarness $topology): array
{
    $php = <<<'PHP'
$appDevNode = \App\Models\Node::query()->where('name', 'app-dev-1')->firstOrFail();

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
        'name' => 'rustfs',
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
    'proxy_route_count' => \App\Models\ProxyRoute::query()
        ->where('domain', \App\Services\S3\S3RouteRegistrar::ServiceDomain)
        ->count(),
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
