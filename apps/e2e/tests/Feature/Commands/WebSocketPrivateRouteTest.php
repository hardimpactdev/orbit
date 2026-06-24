<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('routes the private websocket service to a websocket-role backend through the router', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevWebsocket)
        ->withCurrentCheckout(roles: ['gateway']);

    try {
        expect($topology->lease()->prodApp())
            ->toBeNull()
            ->and($topology->lease()->agent())
            ->toBeNull()
            ->and($topology->lease()->devApp())
            ->not->toBeNull()->and($topology->lease()->gateway())
            ->not->toBeNull()->and($topology->lease()->instance('websocket'))->toBeNull();

        $snapshot = websocketPrivateRouteSnapshot($topology);
        $route = $snapshot['route'];
        $config = $route['config'];
        $runtime = $snapshot['runtime'];
        $renderedRouterRoute = $snapshot['rendered_router_route'];
        $backendPool = $config['router_backend_pool'];
        $backendNodes = array_column($backendPool, 'node');
        $backendUrls = array_column($backendPool, 'url');

        expect($snapshot['schema']['nodes_role_column'])
            ->toBeFalse()
            ->and($snapshot['schema']['nodes_environment_column'])
            ->toBeFalse()
            ->and($snapshot['websocket_role_assignment'])
            ->toMatchArray([
                'role' => 'websocket',
                'status' => 'active',
            ])
            ->and($snapshot['redis_node'])
            ->toBe('app-dev-1')
            ->and($route)
            ->toMatchArray([
                'domain' => 'websocket.orbit',
                'node' => 'gateway',
                'owner_type' => 'router',
                'kind' => 'proxy',
            ])
            ->and($snapshot['proxy_route_count'])
            ->toBe(1)
            ->and($snapshot['app_websocket_public_route_count'])
            ->toBe(0)
            ->and($route['source_hash'])
            ->toBe($route['expected_source_hash'])
            ->and($config['protocol'])
            ->toBe('websocket')
            ->and($config['router_upstream']['node_id'])
            ->toBeInt()
            ->and($config['router_upstream']['node'])
            ->toBe('gateway')
            ->and($config['router_upstream']['url'])
            ->toBe('http://10.6.0.2:80')
            ->and($config['tls'])
            ->toBe([
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                'key_path' => '/etc/orbit/certs/websocket.orbit.key',
            ])
            ->and($config['router_backend_tls'])
            ->toBe([
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ])
            ->and($backendPool)
            ->toHaveCount(1)
            ->and($backendPool[0]['node_id'])
            ->toBeInt()
            ->and($backendPool[0]['node'])
            ->toBe('app-dev-1')
            ->and($backendPool[0]['url'])
            ->toBe('https://10.6.0.4:8080')
            ->and($config['upstreams'][0]['node_id'])
            ->toBeInt()
            ->and($config['upstreams'][0]['node'])
            ->toBe('app-dev-1')
            ->and($config['upstreams'][0]['scheme'])
            ->toBe('https')
            ->and($config['upstreams'][0]['host'])
            ->toBe('10.6.0.4')
            ->and($config['upstreams'][0]['backend_name'])
            ->toBe('10.6.0.4')
            ->and($config['upstreams'][0]['port'])
            ->toBe(8080)
            ->and($config['upstreams'][0]['url'])
            ->toBe('https://10.6.0.4:8080')
            ->and($backendNodes)
            ->toBe(['app-dev-1'])
            ->and($backendUrls)
            ->not->toContain('https://app-dev-1.websocket.orbit:8080');

        expect($renderedRouterRoute)
            ->toContain('websocket.orbit {')
            ->toContain('tls /etc/orbit/certs/websocket.orbit.crt /etc/orbit/certs/websocket.orbit.key')
            ->toContain('reverse_proxy https://10.6.0.4:8080 {')
            ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
            ->toContain('lb_policy first')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('.websocket.orbit:8080');

        expect($runtime['name'])
            ->toContain('dev-orbit-websocket-app-dev-1')
            ->and($runtime['backend_name'])
            ->toBe('10.6.0.4')
            ->and($runtime['command'])
            ->toBe('php '.'artisan reverb:start --host=10.6.0.4 --port=8080 --hostname=10.6.0.4')
            ->and($runtime['network_aliases'])
            ->toContain($runtime['name'])
            ->and(implode(' ', $runtime['network_aliases']))
            ->not
            ->toContain('.websocket.orbit')
            ->and($runtime['environment'])
            ->toMatchArray([
                'BROADCAST_CONNECTION' => 'reverb',
                'ORBIT_WEBSOCKET_APPS_CONFIG' => '/etc/orbit/websocket/apps.php',
                'REDIS_HOST' => '10.6.0.4',
                'REVERB_HOST' => 'websocket.orbit',
                'REVERB_SERVER_HOST' => '10.6.0.4',
                'REVERB_SERVER_PORT' => '8080',
                'REVERB_TLS_CERT' => '/etc/orbit/certs/10.6.0.4.crt',
                'REVERB_TLS_KEY' => '/etc/orbit/certs/10.6.0.4.key',
            ]);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_websocket');

/**
 * @return array{
 *     schema: array{nodes_role_column: bool, nodes_environment_column: bool},
 *     websocket_role_assignment: array{role: string, status: string, settings: array<string, mixed>},
 *     redis_node: string|null,
 *     route: array{
 *         domain: string,
 *         node: string,
 *         owner_type: string,
 *         kind: string,
 *         config: array<string, mixed>,
 *         source_hash: string,
 *         expected_source_hash: string,
 *     },
 *     rendered_router_route: string,
 *     runtime: array{
 *         name: string,
 *         backend_name: string,
 *         command: string,
 *         environment: array<string, string>,
 *         network_aliases: list<string>,
 *     },
 *     proxy_route_count: int,
 *     app_websocket_public_route_count: int,
 * }
 */
function websocketPrivateRouteSnapshot(E2ETopologyHarness $topology): array
{
    $php = <<<'PHP'
        $route = app(\App\Services\WebSockets\WebSocketRouteRegistrar::class)
            ->syncServiceRoute()
            ->load('node');
        $renderer = app(\App\Services\Proxy\ProxyRouteRenderer::class);
        $websocketNode = \App\Models\Node::query()
            ->where('name', 'app-dev-1')
            ->firstOrFail();
        $assignment = $websocketNode->roleAssignments()
            ->where('role', \App\Enums\Nodes\NodeRoleName::WebSocket->value)
            ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
            ->firstOrFail();
        $settings = is_array($assignment->settings) ? $assignment->settings : [];
        $redisNodeId = $settings['redis_node_id'] ?? null;
        $redisNode = is_int($redisNodeId)
            ? \App\Models\Node::query()->find($redisNodeId)
            : null;
        $runtime = app(\App\Services\WebSockets\WebSocketRuntimeContainerRenderer::class)
            ->render($websocketNode, \App\Data\Nodes\RoleSettings\WebSocketRoleSettings::fromArray($settings));

        echo json_encode([
            'schema' => [
                'nodes_role_column' => \Illuminate\Support\Facades\Schema::hasColumn('nodes', 'role'),
                'nodes_environment_column' => \Illuminate\Support\Facades\Schema::hasColumn('nodes', 'environment'),
            ],
            'websocket_role_assignment' => [
                'role' => $assignment->role,
                'status' => $assignment->status,
                'settings' => $settings,
            ],
            'redis_node' => $redisNode?->name,
            'route' => [
                'domain' => $route->domain,
                'node' => $route->node->name,
                'owner_type' => $route->owner_type,
                'kind' => $route->kind,
                'config' => $route->config,
                'source_hash' => $route->source_hash,
                'expected_source_hash' => $renderer->sourceHash($route),
            ],
            'rendered_router_route' => $renderer->renderRouterRoute($route),
            'runtime' => [
                'name' => $runtime->name(),
                'backend_name' => $runtime->backendName(),
                'command' => $runtime->command(),
                'environment' => $runtime->environment(),
                'network_aliases' => $runtime->networkAliases(),
            ],
            'proxy_route_count' => \App\Models\ProxyRoute::query()
                ->where('domain', \App\Services\WebSockets\WebSocketRouteRegistrar::ServiceDomain)
                ->count(),
            'app_websocket_public_route_count' => \App\Models\ProxyRoute::query()
                ->where('owner_type', 'app-websocket')
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
