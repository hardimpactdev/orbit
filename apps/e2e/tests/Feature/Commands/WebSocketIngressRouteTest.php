<?php

declare(strict_types=1);

use App\E2E\Support\E2EGatewayApi;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('delivers public websocket events through ingress and router to websocket-role backends', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket)
        ->withCurrentCheckout(roles: ['gateway']);
    $suffix = strtolower(bin2hex(random_bytes(3)));
    $appName = "ws-public-{$suffix}";
    $appDomain = "{$appName}.example.test";
    $publicHost = "events-{$suffix}.example.test";
    $eventChannel = "orbit-e2e-{$suffix}";
    $eventMessage = "delivered-{$suffix}";

    try {
        expect($topology->lease()->agent())
            ->toBeNull()
            ->and($topology->lease()->devApp())
            ->not->toBeNull()->and($topology->lease()->prodApp())
            ->not->toBeNull()->and($topology->lease()->gateway())
            ->not->toBeNull()->and($topology->lease()->instance('websocket'))->toBeNull();

        E2EGatewayApi::stop($topology->instance('gateway'));

        $snapshot = websocketIngressRouteSnapshot($topology, $appName, $appDomain, $publicHost);
        $publicRoute = $snapshot['public_route'];
        $publicConfig = $publicRoute['config'];
        $serviceRoute = $snapshot['service_route'];
        $serviceConfig = $serviceRoute['config'];
        $publicBackendPool = $publicConfig['router_backend_pool'];
        $serviceBackendPool = $serviceConfig['router_backend_pool'];
        $serviceBackendNodes = array_column($serviceBackendPool, 'node');

        expect($snapshot['schema']['nodes_role_column'])
            ->toBeFalse()
            ->and($snapshot['schema']['nodes_environment_column'])
            ->toBeFalse()
            ->and($snapshot['nodes']['app_prod_roles'])
            ->toContain('app-prod')
            ->and($snapshot['nodes']['app_prod_roles'])
            ->toContain('ingress')
            ->and($snapshot['nodes']['app_dev_roles'])
            ->toContain('app-dev')
            ->and($snapshot['nodes']['app_dev_roles'])
            ->toContain('database')
            ->and($snapshot['nodes']['app_dev_roles'])
            ->toContain('websocket')
            ->and($snapshot['binding'])
            ->toMatchArray([
                'enabled' => true,
                'public_hosts' => [$publicHost],
                'allowed_origins' => ["https://{$appDomain}"],
            ])
            ->and($snapshot['public_route_count_for_app'])
            ->toBe(1)
            ->and($snapshot['service_route_count'])
            ->toBe(1);

        expect($publicRoute['domain'])
            ->toBe($publicHost)
            ->and($publicRoute['node'])
            ->toBe('app-prod-1')
            ->and($publicRoute['owner_type'])
            ->toBe('app-websocket')
            ->and($publicRoute['kind'])
            ->toBe('proxy')
            ->and($publicRoute['source_hash'])
            ->toBe($publicRoute['expected_source_hash'])
            ->and($publicConfig['placement'])
            ->toBe('ingress')
            ->and($publicConfig['protocol'])
            ->toBe('websocket')
            ->and($publicConfig['target'])
            ->toBe([
                'type' => 'websocket',
                'value' => 'https://websocket.orbit',
            ])
            ->and($publicConfig['upstream'])
            ->toBe('https://websocket.orbit')
            ->and($publicConfig['router_upstream']['node_id'])
            ->toBeInt()
            ->and($publicConfig['router_upstream']['node'])
            ->toBe('gateway')
            ->and($publicConfig['router_upstream']['url'])
            ->toBe('http://10.6.0.2:80')
            ->and($publicBackendPool)
            ->toHaveCount(1)
            ->and($publicBackendPool[0]['node_id'])
            ->toBeInt()
            ->and($publicBackendPool[0]['node'])
            ->toBe('app-dev-1')
            ->and($publicBackendPool[0]['url'])
            ->toBe('https://10.6.0.4:8080')
            ->and($publicConfig['router_backend_tls'])
            ->toBe([
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ])
            ->and($publicConfig['router_artifact']['node'])
            ->toBe('gateway')
            ->and($publicConfig['router_artifact']['source_hash'])
            ->toBe($snapshot['public_router_hash']);

        expect($serviceRoute['domain'])
            ->toBe('websocket.orbit')
            ->and($serviceRoute['node'])
            ->toBe('gateway')
            ->and($serviceRoute['owner_type'])
            ->toBe('router')
            ->and($serviceRoute['kind'])
            ->toBe('proxy')
            ->and($serviceRoute['source_hash'])
            ->toBe($serviceRoute['expected_source_hash'])
            ->and($serviceConfig['protocol'])
            ->toBe('websocket')
            ->and($serviceConfig['router_upstream']['node'])
            ->toBe('gateway')
            ->and($serviceConfig['router_upstream']['url'])
            ->toBe('http://10.6.0.2:80')
            ->and($serviceConfig['tls'])
            ->toBe([
                'managed_by' => 'internal',
                'trusted_by_gateway_ca' => true,
                'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                'key_path' => '/etc/orbit/certs/websocket.orbit.key',
            ])
            ->and($serviceConfig['router_backend_tls'])
            ->toBe([
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ])
            ->and($serviceBackendPool)
            ->toHaveCount(1)
            ->and($serviceBackendPool[0]['node_id'])
            ->toBeInt()
            ->and($serviceBackendPool[0]['node'])
            ->toBe('app-dev-1')
            ->and($serviceBackendPool[0]['url'])
            ->toBe('https://10.6.0.4:8080')
            ->and($serviceConfig['upstreams'][0]['host'])
            ->toBe('10.6.0.4')
            ->and($serviceConfig['upstreams'][0]['backend_name'])
            ->toBe('10.6.0.4')
            ->and($serviceConfig['upstreams'][0]['port'])
            ->toBe(8080)
            ->and($serviceBackendNodes)
            ->toBe(['app-dev-1'])
            ->and($serviceBackendNodes)
            ->not->toContain('app-prod-1');

        expect($snapshot['rendered_ingress_route'])
            ->toContain("{$publicHost} {")
            ->toContain("tls /etc/orbit/certs/{$publicHost}.crt /etc/orbit/certs/{$publicHost}.key")
            ->toContain('reverse_proxy http://10.6.0.2:80 {')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('issuer acme')
            ->not->toContain('.websocket.orbit:8080');

        expect($snapshot['rendered_public_router_route'])
            ->toContain("http://{$publicHost} {")
            ->toContain('reverse_proxy https://10.6.0.4:8080 {')
            ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('.websocket.orbit:8080');

        expect($snapshot['rendered_service_router_route'])
            ->toContain('websocket.orbit {')
            ->toContain('tls /etc/orbit/certs/websocket.orbit.crt /etc/orbit/certs/websocket.orbit.key')
            ->toContain('reverse_proxy https://10.6.0.4:8080 {')
            ->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
            ->toContain('stream_close_delay 5m')
            ->toContain('flush_interval -1')
            ->not->toContain('.websocket.orbit:8080')
            ->not->toContain('app-prod-1');

        $delivery = websocketIngressEventDelivery(
            topology: $topology,
            snapshot: $snapshot,
            publicHost: $publicHost,
            channel: $eventChannel,
            message: $eventMessage,
        );

        expect($delivery)
            ->toMatchArray([
                'published' => true,
                'event' => 'orbit.e2e',
                'channel' => $eventChannel,
                'message' => $eventMessage,
            ])
            ->and($delivery['handshake_status'])
            ->toContain('101')
            ->and($delivery['publish_status'])
            ->toContain('200');
    } finally {
        websocketIngressRouteCleanup($topology, $appName, $publicHost);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev_app-prod_websocket');

/**
 * @return array<string, mixed>
 */
function websocketIngressRouteSnapshot(
    E2ETopologyHarness $topology,
    string $appName,
    string $appDomain,
    string $publicHost,
): array {
    $php = <<<'PHP'
        $appName = __APP_NAME__;
        $appDomain = __APP_DOMAIN__;
        $publicHost = __PUBLIC_HOST__;
        $prodNode = \App\Models\Node::query()
            ->where('name', 'app-prod-1')
            ->firstOrFail();
        $websocketNode = \App\Models\Node::query()
            ->where('name', 'app-dev-1')
            ->firstOrFail();

        \App\Models\ProxyRoute::query()
            ->where('domain', $publicHost)
            ->delete();
        \App\Models\Project::query()
            ->where('name', $appName)
            ->delete();

        $app = \App\Models\Project::query()->create([
            'name' => $appName,
            'node_id' => $prodNode->id,
            'environment' => 'production',
            'domain' => $appDomain,
            'path' => "/home/orbit/apps/{$appName}",
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'runtime' => \App\Enums\Apps\AppRuntimeKind::Php->value,
            'worker_enabled' => false,
            'worker_config' => null,
            'deploy_warmup_paths' => null,
            'adopted' => false
        ]);
        $binding = app(\App\Services\WebSockets\WebSocketBindingService::class)
            ->enable($app, [$publicHost])
            ->refresh();
        $publicRoute = \App\Models\ProxyRoute::query()
            ->with('node')
            ->where('domain', $publicHost)
            ->firstOrFail();
        $publicConfig = is_array($publicRoute->config) ? $publicRoute->config : [];
        $publicConfig['tls'] = 'internal';
        $publicRoute->forceFill(['config' => $publicConfig])->save();
        $serviceRoute = \App\Models\ProxyRoute::query()
            ->with('node')
            ->where('domain', \App\Services\WebSockets\WebSocketRouteRegistrar::ServiceDomain)
            ->firstOrFail();

        $gatewayNode = $serviceRoute->node;
        $converger = app(\App\Services\Nodes\Roles\NodeRoleBaselineConverger::class);
        $fixer = app(\App\Services\Proxy\ProxyRouteFixer::class);

        foreach ([$gatewayNode, $prodNode, $websocketNode] as $node) {
            $node->forceFill(['platform' => 'ubuntu'])->save();
        }

        $convergeRole = function (\App\Models\Node $node, string $role) use ($converger): void {
            $assignment = $node->roleAssignments()
                ->where('role', $role)
                ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
                ->firstOrFail();

            $converger->converge($node, $assignment);
        };
        $drift = fn (string $key) => new \App\Data\Doctor\DriftEntry(
            family: 'proxy',
            key: $key,
            kind: \App\Enums\DriftKind::Missing,
            summary: $key,
        );

        $convergeRole($gatewayNode, \App\Enums\Nodes\NodeRoleName::Router->value);
        $convergeRole($prodNode, \App\Enums\Nodes\NodeRoleName::Ingress->value);
        $convergeRole($websocketNode, \App\Enums\Nodes\NodeRoleName::WebSocket->value);

        app(\App\Services\WebSockets\WebSocketRuntimeAppConfigSyncer::class)->sync();

        $fixer->fixCaddyContainer($gatewayNode, $drift('proxy.caddy_container_missing'));
        $fixer->fixCaddyContainer($prodNode, $drift('proxy.caddy_container_missing'));

        $assertCaddyUsesNodeVolume = function (\App\Models\Node $node): void {
            $tool = \App\Models\NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'caddy')
                ->firstOrFail();
            $config = is_array($tool->config) ? $tool->config : [];
            $containerConfig = is_array($config['container'] ?? null) ? $config['container'] : [];
            $container = (string) ($containerConfig['name'] ?? '');
            $shell = app(\App\Contracts\RemoteShell::class);
            $mounts = $shell->run(
                $node,
                sprintf(
                    'docker inspect --format %s %s',
                    escapeshellarg('{{ range .Mounts }}{{ .Destination }}={{ .Source }}{{ "\n" }}{{ end }}'),
                    escapeshellarg($container),
                ),
                ['throw' => true],
            )->stdout;

            if (! str_contains($mounts, '-etc-orbit/_data')) {
                throw new \RuntimeException("Caddy container {$container} on {$node->name} is not using a node /etc/orbit volume. mounts={$mounts}");
            }
        };

        $assertCaddyUsesNodeVolume($gatewayNode);
        $assertCaddyUsesNodeVolume($prodNode);

        $serviceRoute = $serviceRoute->refresh()->load('node');
        $publicRoute = $publicRoute->refresh()->load('node');

        $fixer->fix($serviceRoute, $drift('proxy.tls_missing'));
        $fixer->fix($serviceRoute->refresh()->load('node'), $drift('proxy.route_missing'));
        $fixer->fix($publicRoute, $drift('proxy.tls_missing'));
        $fixer->fix($publicRoute->refresh()->load('node'), $drift('proxy.public_route_missing'));
        $fixer->fix($publicRoute->refresh()->load('node'), $drift('proxy.router_route_missing'));

        $serviceRoute = $serviceRoute->refresh()->load('node');
        $publicRoute = $publicRoute->refresh()->load('node');
        $renderer = app(\App\Services\Proxy\ProxyRouteRenderer::class);
        $renderedPublicRouterRoute = $renderer->renderRouterRoute($publicRoute);
        $renderedServiceRouterRoute = $renderer->renderRouterRoute($serviceRoute);
        $caddyContainer = function (\App\Models\Node $node): string {
            $tool = \App\Models\NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'caddy')
                ->firstOrFail();
            $config = is_array($tool->config) ? $tool->config : [];
            $container = is_array($config['container'] ?? null) ? $config['container'] : [];

            return (string) ($container['name'] ?? '');
        };
        $runtime = app(\App\Services\WebSockets\WebSocketRuntimeContainerRenderer::class)
            ->render($websocketNode, \App\Data\Nodes\RoleSettings\WebSocketRoleSettings::fromArray(
                $websocketNode->roleAssignments()
                    ->where('role', \App\Enums\Nodes\NodeRoleName::WebSocket->value)
                    ->where('status', \App\Enums\Nodes\NodeRoleStatus::Active->value)
                    ->firstOrFail()
                    ->settings ?? [],
            ));

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
                'app_dev_roles' => $websocketNode->roleAssignments()
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
                'reverb_app_id' => $binding->reverb_app_id,
                'reverb_app_key' => $binding->reverb_app_key,
                'reverb_app_secret' => $binding->reverb_app_secret,
            ],
            'runtime' => [
                'container' => $runtime->name(),
            ],
            'caddy' => [
                'ingress_container' => $caddyContainer($prodNode),
                'gateway_container' => $caddyContainer($gatewayNode),
                'ingress_connect_host' => $prodNode->host,
                'gateway_connect_host' => $gatewayNode->host,
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

/**
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function websocketIngressEventDelivery(
    E2ETopologyHarness $topology,
    array $snapshot,
    string $publicHost,
    string $channel,
    string $message,
): array {
    $binding = $snapshot['binding'];
    $caddy = $snapshot['caddy'];

    $php = <<<'PHP'
        <?php

        $publicHost = __PUBLIC_HOST__;
        $origin = __ORIGIN__;
        $ingressCaddy = __INGRESS_CADDY__;
        $gatewayCaddy = __GATEWAY_CADDY__;
        $ingressCaddyContainer = __INGRESS_CADDY_CONTAINER__;
        $gatewayCaddyContainer = __GATEWAY_CADDY_CONTAINER__;
        $runtimeContainer = __RUNTIME_CONTAINER__;
        $prodNodeContainer = __PROD_NODE_CONTAINER__;
        $gatewayNodeContainer = __GATEWAY_NODE_CONTAINER__;
        $appId = __APP_ID__;
        $appKey = __APP_KEY__;
        $appSecret = __APP_SECRET__;
        $channel = __CHANNEL__;
        $message = __MESSAGE__;
        $lastPublishResponse = '';
        $step = 'starting websocket event probe';

        function e2e_fail(string $message): never
        {
            global $gatewayCaddyContainer;
            global $gatewayNodeContainer;
            global $ingressCaddyContainer;
            global $prodNodeContainer;
            global $publicHost;
            global $runtimeContainer;
            global $lastPublishResponse;
            global $step;

            fwrite(STDERR, "[{$step}] {$message}\n");

            if ($lastPublishResponse !== '') {
                fwrite(STDERR, "\n--- last publish response ---\n{$lastPublishResponse}\n");
            }

            e2e_debug_command('ingress caddy logs', 'docker logs --tail=80 '.escapeshellarg($ingressCaddyContainer));
            e2e_debug_command('gateway caddy logs', 'docker logs --tail=80 '.escapeshellarg($gatewayCaddyContainer));
            e2e_debug_command('websocket runtime logs', 'docker logs --tail=80 '.escapeshellarg($runtimeContainer));
            e2e_debug_command('prod addresses', 'docker exec '.escapeshellarg($prodNodeContainer).' sh -lc '.escapeshellarg('ip -br addr'));
            e2e_debug_command('gateway addresses', 'docker exec '.escapeshellarg($gatewayNodeContainer).' sh -lc '.escapeshellarg('ip -br addr'));
            e2e_debug_command('prod to gateway public route', 'docker exec '.escapeshellarg($prodNodeContainer).' sh -lc '.escapeshellarg('curl -sv --max-time 5 http://10.6.0.2/ -H '.escapeshellarg("Host: {$publicHost}")));
            e2e_debug_command('gateway websocket service dns', 'docker exec '.escapeshellarg($gatewayNodeContainer).' sh -lc '.escapeshellarg('getent hosts websocket.orbit || true'));
            e2e_debug_command('websocket runtime app config', 'docker exec '.escapeshellarg($runtimeContainer).' sh -lc '.escapeshellarg('cat /etc/orbit/websocket/apps.php 2>/dev/null || true'));
            e2e_debug_command('websocket runtime laravel log', 'docker exec '.escapeshellarg($runtimeContainer).' sh -lc '.escapeshellarg('cat storage/logs/laravel.log 2>/dev/null || true'));
            exit(1);
        }

        function e2e_step(string $name): void
        {
            global $step;

            $step = $name;
        }

        function e2e_debug_command(string $label, string $command): void
        {
            fwrite(STDERR, "\n--- {$label} ---\n");

            $output = shell_exec($command.' 2>&1');

            fwrite(STDERR, is_string($output) && $output !== '' ? $output : "(no output)\n");
        }

        function e2e_tls_socket(string $connectHost, string $serverName)
        {
            $context = stream_context_create([
                'ssl' => [
                    'SNI_enabled' => true,
                    'peer_name' => $serverName,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
            $socket = @stream_socket_client(
                "tls://{$connectHost}:443",
                $errno,
                $errstr,
                20,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($socket === false) {
                e2e_fail("Could not connect to {$connectHost}:443 for {$serverName}: {$errstr}");
            }

            stream_set_timeout($socket, 20);

            return $socket;
        }

        function e2e_read_bytes($socket, int $length): string
        {
            $buffer = '';

            while (strlen($buffer) < $length) {
                $chunk = fread($socket, $length - strlen($buffer));

                if ($chunk === false) {
                    $lastError = error_get_last();
                    $meta = stream_get_meta_data($socket);
                    $error = is_array($lastError) && is_string($lastError['message'] ?? null)
                        ? $lastError['message']
                        : 'unknown stream error';

                    if (($meta['timed_out'] ?? false) === true) {
                        e2e_fail('Timed out reading from websocket socket.');
                    }

                    e2e_fail("Failed reading from websocket socket: {$error}");
                }

                if ($chunk === '') {
                    $meta = stream_get_meta_data($socket);

                    if (($meta['timed_out'] ?? false) === true) {
                        e2e_fail('Timed out reading from websocket socket.');
                    }

                    if (feof($socket)) {
                        e2e_fail('WebSocket socket closed unexpectedly.');
                    }

                    usleep(50_000);

                    continue;
                }

                $buffer .= $chunk;
            }

            return $buffer;
        }

        function e2e_read_http_headers($socket): string
        {
            $headers = '';

            while (! str_contains($headers, "\r\n\r\n")) {
                $chunk = fgets($socket);

                if ($chunk === false) {
                    e2e_fail('Failed reading HTTP response headers.');
                }

                $headers .= $chunk;
            }

            return $headers;
        }

        function e2e_write_ws_frame($socket, string $payload, int $opcode = 1): void
        {
            $length = strlen($payload);
            $header = chr(0x80 | $opcode);

            if ($length < 126) {
                $header .= chr(0x80 | $length);
            } elseif ($length <= 65_535) {
                $header .= chr(0x80 | 126).pack('n', $length);
            } else {
                $header .= chr(0x80 | 127).pack('J', $length);
            }

            $mask = random_bytes(4);
            $masked = '';

            for ($i = 0; $i < $length; $i++) {
                $masked .= $payload[$i] ^ $mask[$i % 4];
            }

            fwrite($socket, $header.$mask.$masked);
        }

        function e2e_read_ws_text($socket): string
        {
            while (true) {
                $header = e2e_read_bytes($socket, 2);
                $opcode = ord($header[0]) & 0x0f;
                $length = ord($header[1]) & 0x7f;
                $masked = (ord($header[1]) & 0x80) !== 0;

                if ($length === 126) {
                    $length = unpack('n', e2e_read_bytes($socket, 2))[1];
                } elseif ($length === 127) {
                    $parts = unpack('Nhigh/Nlow', e2e_read_bytes($socket, 8));
                    $length = ($parts['high'] << 32) + $parts['low'];
                }

                $mask = $masked ? e2e_read_bytes($socket, 4) : '';
                $payload = $length > 0 ? e2e_read_bytes($socket, $length) : '';

                if ($masked) {
                    $decoded = '';

                    for ($i = 0; $i < $length; $i++) {
                        $decoded .= $payload[$i] ^ $mask[$i % 4];
                    }

                    $payload = $decoded;
                }

                if ($opcode === 9) {
                    e2e_write_ws_frame($socket, $payload, 10);

                    continue;
                }

                if ($opcode === 8) {
                    e2e_fail('WebSocket closed before the expected event was received.');
                }

                if ($opcode === 1) {
                    return $payload;
                }
            }
        }

        function e2e_wait_for_event($socket, string $event, ?string $channel = null): array
        {
            $deadline = time() + 30;

            do {
                $payload = e2e_read_ws_text($socket);
                $frame = json_decode($payload, true);

                if (! is_array($frame)) {
                    continue;
                }

                if (($frame['event'] ?? null) !== $event) {
                    continue;
                }

                if ($channel !== null && ($frame['channel'] ?? null) !== $channel) {
                    continue;
                }

                return $frame;
            } while (time() < $deadline);

            e2e_fail("Timed out waiting for websocket event {$event}.");
        }

        function e2e_publish_event(string $gatewayCaddy, string $appId, string $appKey, string $appSecret, string $channel, string $message): string
        {
            global $lastPublishResponse;

            $body = json_encode([
                'name' => 'orbit.e2e',
                'channels' => [$channel],
                'data' => json_encode(['message' => $message], JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR);
            $query = [
                'auth_key' => $appKey,
                'auth_timestamp' => (string) time(),
                'auth_version' => '1.0',
                'body_md5' => md5($body),
            ];

            ksort($query);

            $path = "/apps/{$appId}/events";
            $signaturePayload = "POST\n{$path}\n".http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $query['auth_signature'] = hash_hmac('sha256', $signaturePayload, $appSecret);
            $target = $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $socket = e2e_tls_socket($gatewayCaddy, 'websocket.orbit');
            $request = "POST {$target} HTTP/1.1\r\n"
                ."Host: websocket.orbit\r\n"
                ."Content-Type: application/json\r\n"
                ."Content-Length: ".strlen($body)."\r\n"
                ."Connection: close\r\n\r\n"
                .$body;

            fwrite($socket, $request);
            $response = stream_get_contents($socket);
            fclose($socket);

            if (! is_string($response) || $response === '') {
                e2e_fail('Publishing through websocket.orbit returned an empty response.');
            }

            $lastPublishResponse = $response;

            return strtok($response, "\r\n") ?: '';
        }

        $socket = e2e_tls_socket($ingressCaddy, $publicHost);
        $websocketKey = base64_encode(random_bytes(16));
        $request = "GET /app/".rawurlencode($appKey)."?protocol=7&client=orbit-e2e&version=1.0&flash=false HTTP/1.1\r\n"
            ."Host: {$publicHost}\r\n"
            ."Origin: {$origin}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$websocketKey}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($socket, $request);
        e2e_step('reading websocket handshake');
        $handshake = e2e_read_http_headers($socket);
        $handshakeStatus = strtok($handshake, "\r\n") ?: '';

        if (! str_contains($handshakeStatus, '101')) {
            e2e_fail("WebSocket handshake failed: {$handshakeStatus}");
        }

        e2e_step('waiting for connection_established');
        e2e_wait_for_event($socket, 'pusher:connection_established');
        e2e_step('subscribing to channel');
        e2e_write_ws_frame($socket, json_encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => $channel],
        ], JSON_THROW_ON_ERROR));
        e2e_step('waiting for subscription_succeeded');
        e2e_wait_for_event($socket, 'pusher_internal:subscription_succeeded', $channel);

        e2e_step('publishing event');
        $publishStatus = e2e_publish_event($gatewayCaddy, $appId, $appKey, $appSecret, $channel, $message);

        if (! str_contains($publishStatus, '200')) {
            e2e_fail("Publishing through websocket.orbit failed: {$publishStatus}");
        }

        e2e_step('waiting for published event');
        $event = e2e_wait_for_event($socket, 'orbit.e2e', $channel);
        $data = $event['data'] ?? null;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data) || ($data['message'] ?? null) !== $message) {
            e2e_fail('Received websocket event payload did not match the published message.');
        }

        fclose($socket);

        echo json_encode([
            'published' => str_contains($publishStatus, '200'),
            'handshake_status' => $handshakeStatus,
            'publish_status' => $publishStatus,
            'event' => $event['event'] ?? null,
            'channel' => $event['channel'] ?? null,
            'message' => $data['message'] ?? null,
        ], JSON_THROW_ON_ERROR);
        PHP;

    $php = strtr($php, [
        '__PUBLIC_HOST__' => var_export($publicHost, true),
        '__ORIGIN__' => var_export($binding['allowed_origins'][0], true),
        '__INGRESS_CADDY__' => var_export($caddy['ingress_connect_host'] ?? $caddy['ingress_container'], true),
        '__GATEWAY_CADDY__' => var_export($caddy['gateway_connect_host'] ?? $caddy['gateway_container'], true),
        '__INGRESS_CADDY_CONTAINER__' => var_export($caddy['ingress_container'], true),
        '__GATEWAY_CADDY_CONTAINER__' => var_export($caddy['gateway_container'], true),
        '__RUNTIME_CONTAINER__' => var_export($snapshot['runtime']['container'], true),
        '__PROD_NODE_CONTAINER__' => var_export($topology->instance('prod')->name(), true),
        '__GATEWAY_NODE_CONTAINER__' => var_export($topology->instance('gateway')->name(), true),
        '__APP_ID__' => var_export($binding['reverb_app_id'], true),
        '__APP_KEY__' => var_export($binding['reverb_app_key'], true),
        '__APP_SECRET__' => var_export($binding['reverb_app_secret'], true),
        '__CHANNEL__' => var_export($channel, true),
        '__MESSAGE__' => var_export($message, true),
    ]);

    $result = $topology->ssh(
        'operator',
        "php <<'PHP'\n{$php}\nPHP",
        timeoutSeconds: 180,
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
        \App\Models\Project::query()
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
