<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class ProxyRouteRenderer
{
    public function __construct(
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private AppProxyRouteTargetResolver $appRouteTargets = new AppProxyRouteTargetResolver,
        private AppProxyRouteRuntimeTargets $appRouteRuntimeTargets = new AppProxyRouteRuntimeTargets,
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    private const string WebSocketStreamCloseDelay = '5m';

    /**
     * S3 PUT/multipart uploads can be arbitrarily large. Caddy must stream
     * request bodies through without buffering them, and must flush response
     * bytes immediately. flush_interval -1 disables Caddy's response buffering.
     * Caddy does not buffer request bodies by default, so no request_buffers
     * directive is needed; omitting it is the correct upload-safe convention.
     */
    private const string S3UploadSafeFlushInterval = '-1';

    /**
     * Container hostname Caddy resolves to the Docker host gateway so custom
     * proxy routes that historically targeted host loopback (127.0.0.1 /
     * localhost) keep reaching the host backend after orbit-caddy moved into
     * a Docker container. The orbit-caddy container configures the
     * matching `--add-host host.docker.internal:host-gateway` entry.
     */
    public const string HostLoopbackHostname = 'host.docker.internal';

    public function render(ProxyRoute $route): string
    {
        if ($this->usesIngressPlacement($route)) {
            return $this->renderIngress($route);
        }

        if ($this->isRouterServiceRoute($route)) {
            return $this->renderRouterRoute($route);
        }

        return match ($route->kind) {
            'app', 'workspace' => $this->renderPhpFastCgi($route),
            'proxy' => $this->renderProxy($route),
            'redirect' => $this->renderRedirect($route),
            default => throw new RuntimeException(
                "Proxy route kind '{$route->kind}' is not renderable by the custom proxy route renderer.",
            ),
        };
    }

    public function sourceHash(ProxyRoute $route): string
    {
        return hash('sha256', $this->render($route));
    }

    public function renderManagedPhpRuntimeIntent(ProxyRoute $route): string
    {
        $this->normalizeManagedPhpRuntimeConfig($route);

        return $this->render($route);
    }

    public function managedPhpRuntimeIntentSourceHash(ProxyRoute $route): string
    {
        return hash('sha256', $this->renderManagedPhpRuntimeIntent($route));
    }

    private function normalizeManagedPhpRuntimeConfig(ProxyRoute $route): void
    {
        if ($this->usesIngressPlacement($route) || ! in_array($route->kind, ['app', 'workspace'], true)) {
            return;
        }

        $route->loadMissing(['instance.app', 'workspace.instance.app']);

        if ($route->kind === 'workspace' && $route->workspace instanceof Workspace) {
            $this->normalizeWorkspacePhpRuntimeConfig($route);

            return;
        }

        $this->normalizeAppPhpRuntimeConfig($route);
    }

    private function normalizeWorkspacePhpRuntimeConfig(ProxyRoute $route): void
    {
        $route->workspace?->loadMissing('instance.app');
        $workspace = $route->workspace;
        $app = $workspace?->instance?->app;

        if (
            ! $workspace instanceof Workspace
            || ! $app instanceof App
            || $app->runtimeKind() !== AppRuntimeKind::Php
        ) {
            return;
        }

        $config = is_array($route->config) ? $route->config : [];
        $config['runtime_upstream'] = "http://orbit-ws-{$app->name}-{$workspace->name}";

        if (! $this->innerTlsPolicy->appliesToWorkspace($workspace)) {
            unset($config['runtime_upstream_tls']);
            $config['php_socket'] = null;
            $route->config = $config;

            return;
        }

        $node = $this->placement->nodeForWorkspace($workspace) ?? $route->node;

        if (! $node instanceof Node) {
            return;
        }

        $config['runtime_upstream'] =
            "https://orbit-ws-{$app->name}-{$workspace->name}:".AppDevelopmentInnerTlsPolicy::InternalTlsPort;
        $config['runtime_upstream_tls'] = $this->innerTlsPolicy->runtimeUpstreamTlsConfig(
            $node,
            $this->innerTlsPolicy->workspaceRouteDomain($workspace),
        );
        $config['php_socket'] = null;
        $route->config = $config;
    }

    private function normalizeAppPhpRuntimeConfig(ProxyRoute $route): void
    {
        $instance = $this->appRouteTargets->instanceForRoute($route);
        $app = $this->appRouteTargets->appForRoute($route, $instance);

        if (! $instance instanceof Instance || ! $app instanceof App || $app->runtimeKind() !== AppRuntimeKind::Php) {
            return;
        }

        $config = is_array($route->config) ? $route->config : [];
        $node = $this->appRouteTargets->nodeForRoute($route, $instance);
        $routeDomain = $this->appRouteTargets->routeDomain($route, $instance);
        $config['target'] = [
            'type' => 'instance',
            'value' => $this->appRouteTargets->selector($instance),
        ];
        $config['instance'] = $this->appRouteRuntimeTargets->instanceConfig($app, $instance, $routeDomain);

        $config['runtime_upstream'] = $this->appRouteRuntimeTargets->httpRuntimeUpstream($app, $instance);

        if (! $node instanceof Node || ! $this->innerTlsPolicy->appliesToAppOnNode($app, $node)) {
            unset($config['runtime_upstream_tls']);
            $config['php_socket'] = null;
            $route->config = $config;

            return;
        }

        $config['runtime_upstream'] = $this->appRouteRuntimeTargets->httpsRuntimeUpstream($app, $instance);
        $config['runtime_upstream_tls'] = $this->innerTlsPolicy->runtimeUpstreamTlsConfig(
            $node,
            $routeDomain,
        );
        $config['php_socket'] = null;
        $route->config = $config;
    }

    public function renderIngress(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $routerUpstream = $config['router_upstream'] ?? null;
        $tls = $this->tlsDirective($route);
        $encode = $this->isWebSocketProtocol($route) || $this->isS3Protocol($route) ? "\n" : "    encode gzip\n\n";
        $streaming = $this->uploadSafeStreamingDirectives($route);
        $analyticsForwardedFor = $this->isAnalyticsProtocol($route)
            ? '        header_up X-Forwarded-For {remote_host}'."\n"
            : '';

        if (! is_array($routerUpstream)) {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a router upstream.");
        }

        $routerUrl = $routerUpstream['url'] ?? null;

        if (! is_string($routerUrl) || $routerUrl === '') {
            throw new RuntimeException('Proxy route router upstream requires a url.');
        }

        $routerUrl = $this->validatedRouterUrl($routerUrl);

        if ($this->isPublicAnalyticsRoute($route)) {
            $analyticsMatcher = $this->analyticsTrackingMatcher($route);

            return <<<CADDY
                {$route->domain} {
                    {$tls}
                {$encode}{$analyticsMatcher}    handle @plausible_tracking {
                        reverse_proxy {$routerUrl} {
                            header_up Host {host}
                            header_up X-Forwarded-Host {host}
                            header_up X-Forwarded-Proto {scheme}
                            header_up X-Forwarded-For {remote_host}
                        }
                    }
                    handle {
                        respond 404
                    }
                }

                CADDY;
        }

        return <<<CADDY
            {$route->domain} {
                {$tls}
            {$encode}    reverse_proxy {$routerUrl} {
            {$streaming}        header_up Host {host}
                    header_up X-Forwarded-Host {host}
                    header_up X-Forwarded-Proto {scheme}
            {$analyticsForwardedFor}    }
            }

            CADDY;
    }

    public function renderRouterRoute(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $routerUpstream = $config['router_upstream'] ?? null;
        $backendPool = $config['router_backend_pool'] ?? null;

        if (! is_array($routerUpstream)) {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a router upstream.");
        }

        $routerUrl = $routerUpstream['url'] ?? null;

        if (! is_string($routerUrl) || $routerUrl === '') {
            throw new RuntimeException('Proxy route router upstream requires a url.');
        }

        $routerHost = parse_url($this->validatedRouterUrl($routerUrl), PHP_URL_HOST);

        if (! is_string($routerHost) || $routerHost === '') {
            throw new RuntimeException('Proxy route router upstream requires a valid bind address.');
        }

        $this->validatedIpAddress($route, $routerHost);

        if (! is_array($backendPool) || $backendPool === []) {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a router backend pool.");
        }

        $backendLines = collect($backendPool)
            ->map(function (mixed $backend): string {
                if (! is_array($backend)) {
                    throw new RuntimeException('Proxy route router backend pool entries must be arrays.');
                }

                $url = $backend['url'] ?? null;

                if (! is_string($url) || $url === '') {
                    throw new RuntimeException('Proxy route router backend pool entries require a url.');
                }

                return $this->validatedBackendUrl($url);
            })
            ->all();

        $upstreams = implode(' ', $backendLines);
        $encode = $this->isWebSocketProtocol($route) || $this->isS3Protocol($route) ? '' : "    encode gzip\n\n";
        $streaming = $this->uploadSafeStreamingDirectives($route);
        $siteAddress = $this->routerSiteAddress($route);
        $siteTls = $this->routerSiteTlsDirective($route);
        $backendTransport = $this->routerBackendTransportDirectives($route);
        $analyticsForwardedFor = $this->isAnalyticsProtocol($route)
            ? '        header_up X-Forwarded-For {http.request.header.X-Forwarded-For}'."\n"
            : '';

        if ($this->isPublicAnalyticsRoute($route)) {
            $analyticsMatcher = $this->analyticsTrackingMatcher($route);

            return <<<CADDY
                {$siteAddress} {
                {$siteTls}{$encode}{$analyticsMatcher}    handle @plausible_tracking {
                        reverse_proxy {$upstreams} {
                            lb_policy first
                            header_up Host {host}
                            header_up X-Forwarded-Host {host}
                            header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
                            header_up X-Forwarded-For {http.request.header.X-Forwarded-For}
                        }
                    }
                    handle {
                        respond 404
                    }
                }

                CADDY;
        }

        return <<<CADDY
            {$siteAddress} {
            {$siteTls}{$encode}    reverse_proxy {$upstreams} {
                    lb_policy first
            {$streaming}        header_up Host {host}
                    header_up X-Forwarded-Host {host}
                    header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
            {$analyticsForwardedFor}{$backendTransport}    }
            }

            CADDY;
    }

    /**
     * @param  array<string, mixed>  $backendArtifact
     */
    public function renderPrivateBackend(ProxyRoute $route, array $backendArtifact): string
    {
        $route->loadMissing(['instance.app', 'workspace.instance.app']);

        $bind = $backendArtifact['bind'] ?? null;
        $documentRoot = $backendArtifact['document_root'] ?? null;
        $runtimeUpstream = $backendArtifact['runtime_upstream'] ?? null;
        $isAppOrWorkspace = in_array($route->kind, ['app', 'workspace'], strict: true);
        $app = $this->owningAppForRoute($route);
        $usesPhpRuntime = $isAppOrWorkspace && $app?->runtime === AppRuntimeKind::Php;
        $isStaticApp = $isAppOrWorkspace && $app?->runtime === AppRuntimeKind::Static;

        if (! is_string($bind) || $bind === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' backend artifact is missing a bind address.");
        }

        $this->validatedIpAddress($route, $bind);

        $pathBlocking = $this->pathBlockingDirective($route);

        $port = OrbitCaddyContainer::PrivateBackendPort;

        if ($usesPhpRuntime) {
            $runtimeUpstream = $this->runtimeUpstreamForRoute($route, $runtimeUpstream);

            if (! is_string($runtimeUpstream) || $runtimeUpstream === '') {
                throw new RuntimeException(
                    "Proxy route '{$route->domain}' backend artifact is missing a runtime container upstream.",
                );
            }

            $runtimeUpstream = $this->validatedHttpUpstream($route, $runtimeUpstream);

            return <<<CADDY
                http://{$route->domain}:{$port} {
                    encode gzip

                    import security_headers
                    import profiling_headers
                    {$pathBlocking}
                    import security_txt
                    import cache_headers

                    reverse_proxy {$runtimeUpstream} {
                        header_up Host {host}
                        header_up X-Forwarded-Host {host}
                        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
                    }
                }

                CADDY;
        }

        if (! is_string($documentRoot) || $documentRoot === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' backend artifact is missing a document root.");
        }

        $documentRoot = $this->validatedAbsolutePath(
            $route,
            $documentRoot,
            'backend artifact has an invalid document root.',
        );

        if ($isStaticApp) {
            return <<<CADDY
                http://{$route->domain}:{$port} {
                    root * {$documentRoot}
                    encode gzip

                    import security_headers
                    import profiling_headers
                    {$pathBlocking}
                    import security_txt
                    import cache_headers
                    file_server
                }

                CADDY;
        }

        // App and workspace routes must have a resolved runtime of
        // `php` or `static`. Reaching this line means the route config is
        // malformed or the runtime is unrecognised.
        throw new RuntimeException(
            "Proxy route '{$route->domain}' backend artifact has an unresolvable runtime target.",
        );
    }

    private function renderProxy(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $upstream = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

        if (! is_string($upstream) || $upstream === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing an upstream target.");
        }

        $upstream = self::normalizeHostLoopback($upstream);

        $tls = $this->tlsDirective($route);

        if ($this->isS3Protocol($route)) {
            return <<<CADDY
                {$route->domain} {
                    {$tls}
                    reverse_proxy {$upstream} {
                        flush_interval -1
                        header_up Host {host}
                        header_up X-Forwarded-Host {host}
                        header_up X-Forwarded-Proto {scheme}
                    }
                }

                CADDY;
        }

        return <<<CADDY
            {$route->domain} {
                {$tls}
                reverse_proxy {$upstream}
            }

            CADDY;
    }

    /**
     * Rewrite host-loopback authorities so persisted custom upstreams keep
     * reaching the host from inside the orbit-caddy container.
     */
    public static function normalizeHostLoopback(string $upstream): string
    {
        return (
            preg_replace_callback(
                '~(?<scheme>https?://)(?<host>127\.0\.0\.1|localhost)(?=$|[:/?\#])~i',
                fn (array $matches): string => $matches['scheme'].self::HostLoopbackHostname,
                $upstream,
            ) ?? $upstream
        );
    }

    private function renderRedirect(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $target = $config['target']['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;
        $code = $this->redirectCode($route, $config['code'] ?? $config['redirect_code'] ?? 302);

        if (! is_string($target) || $target === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a redirect target.");
        }

        $tls = $this->tlsDirective($route);

        return <<<CADDY
            {$route->domain} {
                {$tls}
                redir {$target}{uri} {$code}
            }

            CADDY;
    }

    private function renderPhpFastCgi(ProxyRoute $route): string
    {
        $route->loadMissing(['instance.app', 'workspace.instance.app']);

        $config = is_array($route->config) ? $route->config : [];
        $documentRoot = $config['document_root'] ?? null;
        $runtimeUpstream = $config['runtime_upstream'] ?? null;
        $tls = $this->tlsDirective($route);
        $isAppOrWorkspace = in_array($route->kind, ['app', 'workspace'], true);
        $app = $this->owningAppForRoute($route);
        $usesPhpRuntime = $isAppOrWorkspace && $app?->runtime === AppRuntimeKind::Php;
        $isStaticApp = $isAppOrWorkspace && $app?->runtime === AppRuntimeKind::Static;
        $hibernation = $this->developmentRuntimeHibernationDirectives($route);

        $pathBlocking = $this->pathBlockingDirective($route);

        if ($usesPhpRuntime) {
            $runtimeUpstream = $this->runtimeUpstreamForRoute($route, $runtimeUpstream);

            if (! is_string($runtimeUpstream) || $runtimeUpstream === '') {
                throw new RuntimeException("Proxy route '{$route->domain}' is missing a runtime container upstream.");
            }

            $runtimeUpstream = $this->validatedHttpUpstream($route, $runtimeUpstream);
            $transport = $this->runtimeUpstreamTransportDirectives($route, $config);
            $warmupRetries = $hibernation !== ''
                ? "        lb_try_duration 15s\n        lb_try_interval 250ms\n"
                : '';

            return <<<CADDY
                {$route->domain} {
                    {$tls}
                {$hibernation}
                    encode gzip

                    import security_headers
                    import profiling_headers
                    {$pathBlocking}
                    import security_txt
                    import cache_headers

                    reverse_proxy {$runtimeUpstream} {
                        header_up Host {host}
                        header_up X-Forwarded-Host {host}
                        header_up X-Forwarded-Proto {scheme}
                {$warmupRetries}{$transport}    }
                }

                CADDY;
        }

        if (! is_string($documentRoot) || $documentRoot === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a document root.");
        }

        if ($isStaticApp) {
            return <<<CADDY
                {$route->domain} {
                    {$tls}
                {$hibernation}
                    root * {$documentRoot}
                    encode gzip

                    import security_headers
                    import profiling_headers
                    {$pathBlocking}
                    import security_txt
                    import cache_headers
                    file_server
                }

                CADDY;
        }

        // App and workspace routes must have a resolved runtime of
        // `php` or `static`. Reaching this line means the route config is
        // malformed or the runtime is unrecognised.
        throw new RuntimeException("Proxy route '{$route->domain}' has an unresolvable runtime target.");
    }

    private function developmentRuntimeHibernationDirectives(ProxyRoute $route): string
    {
        $node = $route->node;

        if (! $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-dev')) {
            return '';
        }

        $scope = $this->developmentRuntimeScope($route);

        if ($scope === null) {
            return '';
        }

        $gateway = $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();

        if (
            ! $gateway instanceof Node
            || ! is_string($gateway->wireguard_address)
            || $gateway->wireguard_address === ''
        ) {
            return '';
        }

        $key = "{$scope['type']}-{$scope['id']}";
        $gatewayAddress = $this->validatedIpAddress($route, $gateway->wireguard_address);

        return <<<CADDY
                        log {
                            output file /data/caddy/orbit/hibernation/{$key}.access.log {
                                roll_size 1MiB
                                roll_keep 1
                            }
                            format json
                        }

                        @orbit_runtime_asleep {
                            not file {
                                root /dev/shm/orbit/hibernation
                                try_files /{$key}.awake
                            }
                        }
                        @orbit_runtime_cold {
                            not file {
                                root /dev/shm/orbit/hibernation
                                try_files /{$key}.awake
                            }
                            file {
                                root /data/caddy/orbit/hibernation
                                try_files /{$key}.cold
                            }
                        }
                        forward_auth @orbit_runtime_cold https://{$gatewayAddress} {
                            uri /api/runtime-activations/{$scope['type']}/{$scope['id']}
                            header_up X-Orbit-Runtime-Cold 1
                            transport http {
                                tls_trust_pool file /etc/orbit/ca/root.crt
                                response_header_timeout 90s
                            }
                        }
                        forward_auth @orbit_runtime_asleep https://{$gatewayAddress} {
                            uri /api/runtime-activations/{$scope['type']}/{$scope['id']}
                            header_up X-Orbit-Runtime-Cold 0
                            transport http {
                                tls_trust_pool file /etc/orbit/ca/root.crt
                                response_header_timeout 90s
                            }
                        }

            CADDY;
    }

    /**
     * @return array{type: 'app-instance'|'workspace', id: int}|null
     */
    private function developmentRuntimeScope(ProxyRoute $route): ?array
    {
        if ($route->kind === 'workspace' && $route->workspace instanceof Workspace) {
            return [
                'type' => 'workspace',
                'id' => $route->workspace->id,
            ];
        }

        if ($route->kind !== 'app') {
            return null;
        }

        $instance = $this->appRouteTargets->instanceForRoute($route);

        return (
            $instance instanceof Instance
                ? [
                    'type' => 'app-instance',
                    'id' => $instance->id,
                ]
                : null
        );
    }

    private function tlsDirective(ProxyRoute $route): string
    {
        if ($this->usesPublicAcmeTls($route)) {
            return "tls {\n        issuer acme\n    }";
        }

        $paths = $this->tlsPaths($route);

        return "tls {$paths['cert']} {$paths['key']}";
    }

    private function usesPublicAcmeTls(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $tls = $config['tls'] ?? null;
        $managedBy = is_array($tls)
            ? $tls['managed_by'] ?? $config['tls_managed_by'] ?? null
            : $config['tls_managed_by'] ?? null;

        if ($managedBy === 'acme') {
            return true;
        }

        if (! $this->usesIngressPlacement($route)) {
            return false;
        }

        if (is_array($tls) && ($tls['managed_by'] ?? null) === 'orbit') {
            return true;
        }

        return $tls === null || $tls === [] || is_array($tls);
    }

    private function routerSiteAddress(ProxyRoute $route): string
    {
        if ($this->routerRouteUsesTls($route)) {
            return $route->domain;
        }

        return "http://{$route->domain}";
    }

    private function routerSiteTlsDirective(ProxyRoute $route): string
    {
        if (! $this->routerRouteUsesTls($route)) {
            return '';
        }

        return '    '.$this->tlsDirective($route)."\n";
    }

    private function routerRouteUsesTls(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $tls = $config['tls'] ?? null;

        return (
            is_array($tls)
            && ($tls['trusted_by_gateway_ca'] ?? null) === true
            && (array_key_exists('cert_path', $tls) || array_key_exists('key_path', $tls))
        );
    }

    private function routerBackendTransportDirectives(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $backendTls = $config['router_backend_tls'] ?? null;

        if (! is_array($backendTls) || ($backendTls['trusted_by_gateway_ca'] ?? null) !== true) {
            return '';
        }

        $caPath = $this->validatedAbsolutePath(
            $route,
            is_string($backendTls['ca_path'] ?? null) && $backendTls['ca_path'] !== ''
                ? $backendTls['ca_path']
                : '/etc/orbit/ca/root.crt',
            'has an invalid router backend CA path.',
        );

        return "        transport http {\n"."            tls_trust_pool file {$caPath}\n"."        }\n";
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function tlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $cert = $config['tls']['cert_path'] ?? null;
        $key = $config['tls']['key_path'] ?? null;

        return [
            'cert' => $this->validatedAbsolutePath(
                $route,
                is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
                'has an invalid TLS cert path.',
            ),
            'key' => $this->validatedAbsolutePath(
                $route,
                is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
                'has an invalid TLS key path.',
            ),
        ];
    }

    private function usesIngressPlacement(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['placement'] ?? null) === 'ingress';
    }

    private function isRouterServiceRoute(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return (
            $route->owner_type === 'router'
            && isset($config['router_upstream'])
            && ($config['placement'] ?? null) !== 'ingress'
        );
    }

    private function isWebSocketProtocol(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['protocol'] ?? null) === 'websocket';
    }

    private function isS3Protocol(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['protocol'] ?? null) === 's3';
    }

    private function isAnalyticsProtocol(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['protocol'] ?? null) === 'analytics';
    }

    private function isPublicAnalyticsRoute(ProxyRoute $route): bool
    {
        return $route->owner_type === 'app-analytics' && $this->isAnalyticsProtocol($route);
    }

    private function analyticsTrackingMatcher(ProxyRoute $route): string
    {
        return '    @plausible_tracking path '.implode(' ', $this->analyticsTrackingPaths($route))."\n";
    }

    /**
     * @return list<string>
     */
    private function analyticsTrackingPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $paths = $config['tracking_paths'] ?? null;

        if (! is_array($paths)) {
            return ['/js/*', '/api/event'];
        }

        $validPaths = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || $this->containsUnsafeCharacters($path)) {
                throw new RuntimeException("Proxy route '{$route->domain}' has an invalid analytics tracking path.");
            }

            $validPaths[] = $path;
        }

        return $validPaths !== [] ? array_values(array_unique($validPaths)) : ['/js/*', '/api/event'];
    }

    /**
     * Returns upload-safe or upgrade-safe streaming directives for the
     * reverse_proxy block based on the route protocol.
     *
     * WebSocket routes receive flush_interval -1 and a stream_close_delay so
     * long-lived upgrade connections drain gracefully.
     *
     * S3 routes receive flush_interval -1 only: this disables Caddy's response
     * buffering so large downloads stream immediately. Caddy does not buffer
     * request bodies by default, so PUT/multipart uploads also stream through
     * without a separate directive.
     */
    private function uploadSafeStreamingDirectives(ProxyRoute $route): string
    {
        if ($this->isWebSocketProtocol($route)) {
            return "        flush_interval -1\n        stream_close_delay ".self::WebSocketStreamCloseDelay."\n";
        }

        if ($this->isS3Protocol($route)) {
            return '        flush_interval '.self::S3UploadSafeFlushInterval."\n";
        }

        return '';
    }

    private function redirectCode(ProxyRoute $route, mixed $value): int
    {
        if (is_int($value)) {
            $code = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $code = (int) $value;
        } else {
            throw new RuntimeException("Proxy route '{$route->domain}' has an invalid redirect code.");
        }

        if ($code < 300 || $code > 399) {
            throw new RuntimeException("Proxy route '{$route->domain}' has an invalid redirect code.");
        }

        return $code;
    }

    private function validatedBackendUrl(string $url): string
    {
        if (
            $this->containsUnsafeCharacters($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#', $url) !== 1
        ) {
            throw new RuntimeException('Proxy route backend pool entries require a valid http or https url.');
        }

        return $url;
    }

    private function validatedRouterUrl(string $url): string
    {
        if (
            $this->containsUnsafeCharacters($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#', $url) !== 1
        ) {
            throw new RuntimeException('Proxy route router upstream requires a valid http or https url.');
        }

        return $url;
    }

    private function validatedIpAddress(ProxyRoute $route, string $value): string
    {
        if ($this->containsUnsafeCharacters($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Proxy route '{$route->domain}' backend artifact has an invalid bind address.");
        }

        return $value;
    }

    private function validatedAbsolutePath(ProxyRoute $route, string $value, string $suffix): string
    {
        if ($this->containsUnsafeCharacters($value) || ! str_starts_with($value, '/')) {
            throw new RuntimeException("Proxy route '{$route->domain}' {$suffix}");
        }

        return $value;
    }

    /**
     * Derive the FrankenPHP runtime container upstream from the app or
     * workspace identity when the persisted route config has none. This
     * backstops legacy route rows (or backend artifacts) carried over from
     * origin/main, where configs only contained `php_socket`. The migration
     * backfills most rows; this method handles edge cases (adopted rows,
     * restore from an older snapshot, app-only fixtures) so ProxyRouteFixer /
     * doctor restore can repair instead of throwing. Returns the existing
     * upstream if present; never revives php_fastcgi for app or workspace
     * routes.
     */
    private function deriveRuntimeUpstreamIfMissing(ProxyRoute $route, mixed $current): ?string
    {
        if (is_string($current) && $current !== '') {
            return $current;
        }

        $route->loadMissing(['instance.app', 'workspace.instance.app']);
        $app = $this->owningAppForRoute($route);

        if (! $app instanceof App) {
            return null;
        }

        $slug = $app->name;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $instance = $this->appRouteTargets->instanceForRoute($route);

        if ($this->usesIngressPlacement($route)) {
            if ($route->kind === 'workspace' && $route->workspace !== null) {
                return "http://orbit-ws-{$slug}-{$route->workspace->name}";
            }

            return $instance instanceof Instance
                ? $this->appRouteRuntimeTargets->httpRuntimeUpstream($app, $instance)
                : null;
        }

        if ($route->kind === 'workspace' && $route->workspace !== null) {
            if ($this->innerTlsPolicy->appliesToWorkspace($route->workspace)) {
                return (
                    "https://orbit-ws-{$slug}-{$route->workspace?->name}:".AppDevelopmentInnerTlsPolicy::InternalTlsPort
                );
            }

            return "http://orbit-ws-{$slug}-{$route->workspace?->name}";
        }

        $node = $this->appRouteTargets->nodeForRoute($route, $instance);

        if (
            $instance instanceof Instance
            && $node instanceof Node
            && $this->innerTlsPolicy->appliesToAppOnNode($app, $node)
        ) {
            return $this->appRouteRuntimeTargets->httpsRuntimeUpstream($app, $instance);
        }

        return $instance instanceof Instance
            ? $this->appRouteRuntimeTargets->httpRuntimeUpstream($app, $instance)
            : null;
    }

    private function runtimeUpstreamForRoute(ProxyRoute $route, mixed $current): ?string
    {
        if (! $this->usesIngressPlacement($route)) {
            if ($route->kind === 'workspace') {
                if (
                    $route->workspace instanceof Workspace
                    && $this->innerTlsPolicy->appliesToWorkspace($route->workspace)
                ) {
                    $route->workspace->loadMissing('instance.app');
                    $app = $route->workspace?->instance?->app;

                    if ($app instanceof App && is_string($app->name) && $app->name !== '') {
                        return (
                            "https://orbit-ws-{$app->name}-{$route->workspace?->name}:"
                            .AppDevelopmentInnerTlsPolicy::InternalTlsPort
                        );
                    }
                }

                return $this->deriveRuntimeUpstreamIfMissing($route, $current);
            }

            $instance = $this->appRouteTargets->instanceForRoute($route);
            $app = $this->appRouteTargets->appForRoute($route, $instance);

            if ($instance instanceof Instance && $app instanceof App) {
                $node = $this->appRouteTargets->nodeForRoute($route, $instance);

                if ($node instanceof Node && $this->innerTlsPolicy->appliesToAppOnNode($app, $node)) {
                    return $this->appRouteRuntimeTargets->httpsRuntimeUpstream($app, $instance);
                }
            }
        }

        return $this->deriveRuntimeUpstreamIfMissing($route, $current);
    }

    private function owningAppForRoute(ProxyRoute $route): ?App
    {
        if ($route->kind === 'workspace') {
            $route->loadMissing('workspace.instance.app');

            return $route->workspace?->instance?->app;
        }

        $instance = $this->appRouteTargets->instanceForRoute($route);

        return $this->appRouteTargets->appForRoute($route, $instance);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runtimeUpstreamTransportDirectives(ProxyRoute $route, array $config): string
    {
        if ($this->usesIngressPlacement($route)) {
            return '';
        }

        $runtimeUpstreamTls = $config['runtime_upstream_tls'] ?? null;

        if (! is_array($runtimeUpstreamTls)) {
            $runtimeUpstreamTls = $this->deriveRuntimeUpstreamTls($route);
        }

        if (! is_array($runtimeUpstreamTls)) {
            return '';
        }

        return $this->innerTlsPolicy->runtimeUpstreamTransportDirectives($runtimeUpstreamTls);
    }

    private function pathBlockingDirective(ProxyRoute $route): string
    {
        // Document root is instance-authoritative; App owns none. A '.' root
        // (serve from project root) uses the project-root path-blocking import.
        // Resolve the route's concrete workspace/instance placement — the app
        // primary is used only for truly app-level bare routes.
        return $this->routeDocumentRoot($route) === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';
    }

    private function routeDocumentRoot(ProxyRoute $route): string
    {
        $workspace = $route->workspace;

        if ($route->kind === 'workspace' && $workspace instanceof Workspace) {
            return $this->placement->documentRootForWorkspace($workspace);
        }

        $instance = $this->appRouteTargets->instanceForRoute($route);
        $app = $this->appRouteTargets->appForRoute($route, $instance);

        if (! $instance instanceof Instance || ! $app instanceof App) {
            return '';
        }

        return $this->placement->runtimeDocumentRoot($app, $instance);
    }

    /**
     * @return array{trusted_by_gateway_ca: bool, ca_path: string, server_name: string}|null
     */
    private function deriveRuntimeUpstreamTls(ProxyRoute $route): ?array
    {
        $route->loadMissing(['instance.app', 'workspace.instance.app', 'node']);

        $workspace = $route->workspace;

        if ($route->kind === 'workspace' && $workspace instanceof Workspace) {
            if (! $this->innerTlsPolicy->appliesToWorkspace($workspace)) {
                return null;
            }

            $app = $workspace->instance?->app;

            if (! $app instanceof App) {
                return null;
            }

            $node = $this->placement->nodeForWorkspace($workspace);

            if (! $node instanceof Node) {
                return null;
            }

            return $this->innerTlsPolicy->runtimeUpstreamTlsConfig(
                $node,
                $this->innerTlsPolicy->workspaceRouteDomain($workspace),
            );
        }

        $instance = $this->appRouteTargets->instanceForRoute($route);
        $app = $this->appRouteTargets->appForRoute($route, $instance);

        if ($instance instanceof Instance && $app instanceof App) {
            $node = $this->appRouteTargets->nodeForRoute($route, $instance);

            if (! $node instanceof Node || ! $this->innerTlsPolicy->appliesToAppOnNode($app, $node)) {
                return null;
            }

            $domain = $this->appRouteTargets->routeDomain($route, $instance);

            return $this->innerTlsPolicy->runtimeUpstreamTlsConfig(
                $node,
                $domain,
            );
        }

        return null;
    }

    private function validatedHttpUpstream(ProxyRoute $route, string $value): string
    {
        if (
            $this->containsUnsafeCharacters($value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#', $value) !== 1
        ) {
            throw new RuntimeException("Proxy route '{$route->domain}' has an invalid runtime container upstream.");
        }

        return $value;
    }

    private function containsUnsafeCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F\s]/', $value) === 1;
    }
}
