<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('routes public websocket hosts through ingress and router to websocket-role backends', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket)
        ->withCurrentCheckout(roles: ['gateway']);
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $appName = "ws-public-{$suffix}";
    $appDomain = "{$appName}.example.test";
    $publicHost = "events-{$suffix}.example.test";

    try {
        expect($topology->lease()->agent())->toBeNull()
            ->and($topology->lease()->devApp())->not->toBeNull()
            ->and($topology->lease()->prodApp())->not->toBeNull()
            ->and($topology->lease()->gateway())->not->toBeNull()
            ->and($topology->lease()->instance('websocket'))->not->toBeNull();

        $snapshot = websocketIngressRouteSnapshot($topology, $appName, $appDomain, $publicHost);
        $publicRoute = $snapshot['public_route'];
        $publicConfig = $publicRoute['config'];
        $serviceRoute = $snapshot['service_route'];
        $serviceConfig = $serviceRoute['config'];
        $publicBackendPool = $publicConfig['router_backend_pool'];
        $serviceBackendPool = $serviceConfig['router_backend_pool'];
        $serviceBackendNodes = array_column($serviceBackendPool, 'node');

        expect($snapshot['schema']['nodes_role_column'])->toBeFalse()
            ->and($snapshot['schema']['nodes_environment_column'])->toBeFalse()
            ->and($snapshot['nodes']['app_prod_roles'])->toContain('app-prod')
            ->and($snapshot['nodes']['app_prod_roles'])->toContain('ingress')
            ->and($snapshot['nodes']['websocket_roles'])->toBe(['websocket'])
            ->and($snapshot['binding'])->toMatchArray([
                'enabled' => true,
                'public_hosts' => [$publicHost],
                'allowed_origins' => ["https://{$appDomain}"],
            ])
            ->and($snapshot['public_route_count_for_app'])->toBe(1)
            ->and($snapshot['service_route_count'])->toBe(1);

        expect($publicRoute['domain'])->toBe($publicHost)
            ->and($publicRoute['node'])->toBe('app-prod-1')
            ->and($publicRoute['owner_type'])->toBe('app-websocket')
            ->and($publicRoute['kind'])->toBe('proxy')
            ->and($publicRoute['source_hash'])->toBe($publicRoute['expected_source_hash'])
            ->and($publicConfig['placement'])->toBe('ingress')
            ->and($publicConfig['protocol'])->toBe('websocket')
            ->and($publicConfig['target'])->toBe([
                'type' => 'websocket',
                'value' => 'https://websocket.orbit',
            ])
            ->and($publicConfig['upstream'])->toBe('https://websocket.orbit')
            ->and($publicConfig['router_upstream']['node_id'])->toBeInt()
            ->and($publicConfig['router_upstream']['node'])->toBe('gateway')
            ->and($publicConfig['router_upstream']['url'])->toBe('http://10.6.0.2:80')
            ->and($publicBackendPool)->toHaveCount(1)
            ->and($publicBackendPool[0]['node_id'])->toBeInt()
            ->and($publicBackendPool[0]['node'])->toBe('gateway')
            ->and($publicBackendPool[0]['url'])->toBe('https://websocket.orbit')
            ->and($publicConfig['router_artifact']['node'])->toBe('gateway')
            ->and($publicConfig['router_artifact']['source_hash'])->toBe($snapshot['public_router_hash']);

        expect($serviceRoute['domain'])->toBe('websocket.orbit')
            ->and($serviceRoute['node'])->toBe('gateway')
            ->and($serviceRoute['owner_type'])->toBe('websocket')
            ->and($serviceRoute['kind'])->toBe('proxy')
            ->and($serviceRoute['source_hash'])->toBe($serviceRoute['expected_source_hash'])
            ->and($serviceConfig['protocol'])->toBe('websocket')
            ->and($serviceConfig['router_upstream']['node'])->toBe('gateway')
            ->and($serviceConfig['router_upstream']['url'])->toBe('http://10.6.0.2:80')
            ->and($serviceBackendPool)->toHaveCount(1)
            ->and($serviceBackendPool[0]['node_id'])->toBeInt()
            ->and($serviceBackendPool[0]['node'])->toBe('ws-1')
            ->and($serviceBackendPool[0]['url'])->toBe('https://ws-1.websocket.orbit:8080')
            ->and($serviceConfig['upstreams'][0]['host'])->toBe('ws-1.websocket.orbit')
            ->and($serviceConfig['upstreams'][0]['port'])->toBe(8080)
            ->and($serviceBackendNodes)->toBe(['ws-1'])
            ->and($serviceBackendNodes)->not->toContain('app-dev-1')
            ->and($serviceBackendNodes)->not->toContain('app-prod-1');

        expect($snapshot['rendered_ingress_route'])
            ->toContain("{$publicHost} {")
            ->toContain('reverse_proxy http://10.6.0.2:80 {')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('ws-1.websocket.orbit')
            ->not->toContain('app-dev-1');

        expect($snapshot['rendered_public_router_route'])
            ->toContain("http://{$publicHost} {")
            ->toContain('reverse_proxy https://websocket.orbit {')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('ws-1.websocket.orbit')
            ->not->toContain('app-dev-1');

        expect($snapshot['rendered_service_router_route'])
            ->toContain('http://websocket.orbit {')
            ->toContain('reverse_proxy https://ws-1.websocket.orbit:8080 {')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('app-dev-1')
            ->not->toContain('app-prod-1');
    } finally {
        websocketIngressRouteCleanup($topology, $appName, $publicHost);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod_websocket');

/**
 * @return array<string, mixed>
 */
function websocketIngressRouteSnapshot(E2ETopologyHarness $topology, string $appName, string $appDomain, string $publicHost): array
{
    $php = <<<'PHP'
$appName = __APP_NAME__;
$appDomain = __APP_DOMAIN__;
$publicHost = __PUBLIC_HOST__;
$prodNode = \App\Models\Node::query()
    ->where('name', 'app-prod-1')
    ->firstOrFail();
$websocketNode = \App\Models\Node::query()
    ->where('name', 'ws-1')
    ->firstOrFail();

\App\Models\ProxyRoute::query()
    ->where('domain', $publicHost)
    ->delete();
\App\Models\App::query()
    ->where('name', $appName)
    ->delete();

$app = \App\Models\App::query()->create([
    'name' => $appName,
    'node_id' => $prodNode->id,
    'environment' => 'production',
    'domain' => $appDomain,
    'path' => "/home/orbit/apps/{$appName}",
    'document_root' => 'public',
    'repository' => null,
    'php_version' => '8.5',
    'runtime_kind' => \App\Enums\Apps\AppRuntimeKind::Php->value,
    'worker_enabled' => false,
    'worker_config' => null,
    'deploy_warmup_paths' => null,
    'adopted' => false,
    'agent_ide_config' => null,
]);
$binding = app(\App\Services\WebSockets\WebSocketBindingService::class)
    ->enable($app, [$publicHost])
    ->refresh();
$publicRoute = \App\Models\ProxyRoute::query()
    ->with('node')
    ->where('domain', $publicHost)
    ->firstOrFail();
$serviceRoute = \App\Models\ProxyRoute::query()
    ->with('node')
    ->where('domain', \App\Services\WebSockets\WebSocketRouteRegistrar::ServiceDomain)
    ->firstOrFail();
$renderer = app(\App\Services\Proxy\ProxyRouteRenderer::class);
$renderedPublicRouterRoute = $renderer->renderRouterRoute($publicRoute);
$renderedServiceRouterRoute = $renderer->renderRouterRoute($serviceRoute);

echo json_encode([
    'schema' => [
        'nodes_role_column' => \Illuminate\Support\Facades\Schema::hasColumn('nodes', 'role'),
        'nodes_environment_column' => \Illuminate\Support\Facades\Schema::hasColumn('nodes', 'environment'),
    ],
    'nodes' => [
        'app_prod_roles' => $prodNode->roleAssignments()
            ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->pluck('role')
            ->values()
            ->all(),
        'websocket_roles' => $websocketNode->roleAssignments()
            ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->pluck('role')
            ->values()
            ->all(),
    ],
    'binding' => [
        'enabled' => $binding->enabled,
        'public_hosts' => $binding->public_hosts,
        'allowed_origins' => $binding->allowed_origins,
    ],
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
    'rendered_public_router_route' => $renderedPublicRouterRoute,
    'public_router_hash' => hash('sha256', $renderedPublicRouterRoute),
    'rendered_service_router_route' => $renderedServiceRouterRoute,
    'public_route_count_for_app' => \App\Models\ProxyRoute::query()
        ->where('app_id', $app->id)
        ->where('owner_type', 'app-websocket')
        ->count(),
    'service_route_count' => \App\Models\ProxyRoute::query()
        ->where('domain', \App\Services\WebSockets\WebSocketRouteRegistrar::ServiceDomain)
        ->count(),
], JSON_THROW_ON_ERROR);
PHP;

    $php = strtr($php, [
        '__APP_NAME__' => var_export($appName, true),
        '__APP_DOMAIN__' => var_export($appDomain, true),
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

function websocketIngressRouteCleanup(E2ETopologyHarness $topology, string $appName, string $publicHost): void
{
    if ($topology->checkouts() === []) {
        return;
    }

    $php = <<<'PHP'
$appName = __APP_NAME__;
$publicHost = __PUBLIC_HOST__;

\App\Models\ProxyRoute::query()
    ->where('domain', $publicHost)
    ->delete();
\App\Models\App::query()
    ->where('name', $appName)
    ->delete();
PHP;

    $php = strtr($php, [
        '__APP_NAME__' => var_export($appName, true),
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
