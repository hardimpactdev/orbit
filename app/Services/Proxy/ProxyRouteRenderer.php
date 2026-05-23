<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\ProxyRoute;
use App\Services\Runtime\OrbitCaddyContainer;
use RuntimeException;

final readonly class ProxyRouteRenderer
{
    /**
     * Container hostname Caddy resolves to the Docker host gateway so custom
     * proxy routes that historically targeted host loopback (127.0.0.1 /
     * localhost) keep reaching the host backend after orbit-caddy moved into
     * a Docker container. The orbit-caddy container configures the
     * matching `--add-host host.docker.internal:host-gateway` entry.
     */
    public const HostLoopbackHostname = 'host.docker.internal';

    public function render(ProxyRoute $route): string
    {
        if ($this->usesIngressPlacement($route)) {
            return $this->renderIngress($route);
        }

        return match ($route->kind) {
            'app', 'workspace' => $this->renderPhpFastCgi($route),
            'proxy' => $this->renderProxy($route),
            'redirect' => $this->renderRedirect($route),
            default => throw new RuntimeException("Proxy route kind '{$route->kind}' is not renderable by the custom proxy route renderer."),
        };
    }

    public function sourceHash(ProxyRoute $route): string
    {
        return hash('sha256', $this->render($route));
    }

    public function renderIngress(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $routerUpstream = $config['router_upstream'] ?? null;
        $tls = $this->tlsDirective($route);

        if (! is_array($routerUpstream)) {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing a router upstream.");
        }

        $routerUrl = $routerUpstream['url'] ?? null;

        if (! is_string($routerUrl) || $routerUrl === '') {
            throw new RuntimeException('Proxy route router upstream requires a url.');
        }

        $routerUrl = $this->validatedRouterUrl($routerUrl);

        return <<<CADDY
{$route->domain} {
    {$tls}
    encode gzip

    reverse_proxy {$routerUrl} {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
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

        return <<<CADDY
http://{$route->domain} {
    encode gzip

    reverse_proxy {$upstreams} {
        lb_policy first
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
    }
}

CADDY;
    }

    /**
     * @param  array<string, mixed>  $backendArtifact
     */
    public function renderPrivateBackend(ProxyRoute $route, array $backendArtifact): string
    {
        $route->loadMissing('app');

        $bind = $backendArtifact['bind'] ?? null;
        $documentRoot = $backendArtifact['document_root'] ?? null;
        $runtimeUpstream = $backendArtifact['runtime_upstream'] ?? null;
        $isAppOrWorkspace = in_array($route->kind, ['app', 'workspace'], true);
        $usesPhpRuntime = $isAppOrWorkspace && $route->app?->runtime_kind === AppRuntimeKind::Php;
        $isStaticApp = $isAppOrWorkspace && $route->app?->runtime_kind === AppRuntimeKind::Static;

        if (! is_string($bind) || $bind === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' backend artifact is missing a bind address.");
        }

        $this->validatedIpAddress($route, $bind);

        $pathBlocking = $route->app?->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        $port = OrbitCaddyContainer::PrivateBackendPort;

        if ($usesPhpRuntime) {
            $runtimeUpstream = $this->deriveRuntimeUpstreamIfMissing($route, $runtimeUpstream);

            if (! is_string($runtimeUpstream) || $runtimeUpstream === '') {
                throw new RuntimeException("Proxy route '{$route->domain}' backend artifact is missing a runtime container upstream.");
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
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY;
        }

        if (! is_string($documentRoot) || $documentRoot === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' backend artifact is missing a document root.");
        }

        $documentRoot = $this->validatedAbsolutePath($route, $documentRoot, 'backend artifact has an invalid document root.');

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

        // App and workspace routes must have a resolved runtime_kind of
        // `php` or `static`. Reaching this line means the route config is
        // malformed or the runtime_kind is unrecognised.
        throw new RuntimeException("Proxy route '{$route->domain}' backend artifact has an unresolvable runtime target.");
    }

    private function renderProxy(ProxyRoute $route): string
    {
        $config = is_array($route->config) ? $route->config : [];
        $upstream = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

        if (! is_string($upstream) || $upstream === '') {
            throw new RuntimeException("Proxy route '{$route->domain}' is missing an upstream target.");
        }

        $upstream = $this->normalizeHostLoopback($upstream);

        $tls = $this->tlsDirective($route);

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
        return preg_replace_callback(
            '~(?<scheme>https?://)(?<host>127\.0\.0\.1|localhost)(?=$|[:/?\#])~i',
            fn (array $matches): string => $matches['scheme'].self::HostLoopbackHostname,
            $upstream,
        ) ?? $upstream;
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
        $route->loadMissing('app');

        $config = is_array($route->config) ? $route->config : [];
        $documentRoot = $config['document_root'] ?? null;
        $runtimeUpstream = $config['runtime_upstream'] ?? null;
        $tls = $this->tlsDirective($route);
        $isAppOrWorkspace = in_array($route->kind, ['app', 'workspace'], true);
        $usesPhpRuntime = $isAppOrWorkspace && $route->app?->runtime_kind === AppRuntimeKind::Php;
        $isStaticApp = $isAppOrWorkspace && $route->app?->runtime_kind === AppRuntimeKind::Static;

        $pathBlocking = $route->app?->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        if ($usesPhpRuntime) {
            $runtimeUpstream = $this->deriveRuntimeUpstreamIfMissing($route, $runtimeUpstream);

            if (! is_string($runtimeUpstream) || $runtimeUpstream === '') {
                throw new RuntimeException("Proxy route '{$route->domain}' is missing a runtime container upstream.");
            }

            $runtimeUpstream = $this->validatedHttpUpstream($route, $runtimeUpstream);

            return <<<CADDY
{$route->domain} {
    {$tls}
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
    }
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

        // App and workspace routes must have a resolved runtime_kind of
        // `php` or `static`. Reaching this line means the route config is
        // malformed or the runtime_kind is unrecognised.
        throw new RuntimeException("Proxy route '{$route->domain}' has an unresolvable runtime target.");
    }

    private function tlsDirective(ProxyRoute $route): string
    {
        $paths = $this->tlsPaths($route);

        return "tls {$paths['cert']} {$paths['key']}";
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
        if ($this->containsUnsafeCharacters($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('#^https?://#', $url) !== 1
        ) {
            throw new RuntimeException('Proxy route backend pool entries require a valid http or https url.');
        }

        return $url;
    }

    private function validatedRouterUrl(string $url): string
    {
        if ($this->containsUnsafeCharacters($url)
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

        $route->loadMissing('app', 'workspace');

        if ($route->app === null) {
            return null;
        }

        $slug = $route->app->name;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        if ($route->kind === 'workspace' && $route->workspace !== null) {
            return "http://orbit-ws-{$slug}-{$route->workspace->name}";
        }

        return "http://orbit-app-{$slug}";
    }

    private function validatedHttpUpstream(ProxyRoute $route, string $value): string
    {
        if ($this->containsUnsafeCharacters($value)
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
