<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\AppProxyRouteTargetResolver;
use App\Services\Proxy\WorkspaceProxyRouteOwnershipResolver;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * Resolves the process `app` hostname selector through exact proxy_routes.domain
 * precedence so custom app-instance and workspace hostnames work.
 *
 * The `app` selector is a strict hostname (no scheme, path, or port). Browser
 * Origin is parsed separately as an http(s) URL.
 */
/** @mago-expect lint:cyclomatic-complexity */
/** @mago-expect lint:kan-defect */
final readonly class ProcessAppHostnameResolver
{
    public function __construct(
        private AppProxyRouteTargetResolver $appRouteTargets,
        private WorkspacePlacement $placement,
        private WorkspaceProxyRouteOwnershipResolver $workspaceRouteOwnership,
    ) {}

    public function resolve(string $hostname): ProcessOwnerContext
    {
        $domain = $this->assertStrictHostname($hostname);

        $route = ProxyRoute::query()
            ->with(['instance.app', 'workspace.instance.app', 'node'])
            ->where('domain', $domain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            throw new GatewayApiException(
                "App hostname '{$domain}' is not registered.",
                'validation_failed',
                [
                    'field' => 'app',
                    'value' => $domain,
                ],
            );
        }

        if ($this->isWorkspaceRoute($route)) {
            return $this->contextForWorkspaceRoute($route, $domain);
        }

        if ($this->isAppRoute($route)) {
            return $this->contextForAppRoute($route, $domain);
        }

        throw new GatewayApiException(
            "App hostname '{$domain}' is not an app or workspace proxy route.",
            'validation_failed',
            [
                'field' => 'app',
                'value' => $domain,
                'owner_type' => $route->owner_type,
                'kind' => $route->kind,
            ],
        );
    }

    /**
     * Strict process `app` selector: hostname only, no scheme/path/port/userinfo.
     */
    public function assertStrictHostname(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new GatewayApiException(
                'An app hostname is required.',
                'validation_failed',
                ['field' => 'app'],
            );
        }

        if (
            str_contains($value, '://')
            || str_contains($value, '/')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, '@')
            || str_contains($value, ' ')
            || str_contains($value, '\\')
            || preg_match('/:\d+$/', $value) === 1
        ) {
            throw new GatewayApiException(
                'App selector must be a hostname only (no scheme, path, or port).',
                'validation_failed',
                [
                    'field' => 'app',
                    'value' => $value,
                    'reason' => 'hostname_only',
                ],
            );
        }

        return mb_strtolower($value);
    }

    /**
     * Parse a browser Origin as an http(s) URL and return its host, or null.
     *
     * Only default-port origins are admitted under the proxy-route domain-only
     * model: http with no port or :80, https with no port or :443. Explicit
     * non-default ports are a distinct browser origin and are rejected.
     */
    public function hostnameFromBrowserOrigin(string $origin): ?string
    {
        $origin = trim($origin);

        if ($origin === '') {
            return null;
        }

        $parts = parse_url($origin);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        $port = $parts['port'] ?? null;

        if (! is_string($scheme)) {
            return null;
        }

        $scheme = mb_strtolower($scheme);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        if ($path !== '' && $path !== '/') {
            return null;
        }

        if (
            ($parts['query'] ?? null) !== null
            || ($parts['fragment'] ?? null) !== null
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
        ) {
            return null;
        }

        if ($port !== null) {
            if (! is_int($port)) {
                return null;
            }

            $defaultPort = $scheme === 'https' ? 443 : 80;

            if ($port !== $defaultPort) {
                return null;
            }
        }

        return mb_strtolower($host);
    }

    private function isAppRoute(ProxyRoute $route): bool
    {
        return $route->owner_type === 'app' && $route->kind === 'app';
    }

    private function isWorkspaceRoute(ProxyRoute $route): bool
    {
        return $route->owner_type === 'workspace' && $route->kind === 'workspace';
    }

    private function contextForAppRoute(ProxyRoute $route, string $domain): ProcessOwnerContext
    {
        $instance = $this->appRouteTargets->instanceForRoute($route);
        $app = $this->appRouteTargets->appForRoute($route, $instance);

        if (! $app instanceof App || ! $instance instanceof Instance) {
            throw new GatewayApiException(
                "App hostname '{$domain}' does not resolve to a concrete app instance.",
                'validation_failed',
                [
                    'field' => 'app',
                    'value' => $domain,
                    'reason' => 'instance_required',
                ],
            );
        }

        $node = $this->appRouteTargets->nodeForRoute($route, $instance);

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                "App hostname '{$domain}' instance has no node.",
                'validation_failed',
                [
                    'field' => 'app',
                    'value' => $domain,
                ],
            );
        }

        return new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: null,
            owner: $app,
            instance: $instance,
        );
    }

    private function contextForWorkspaceRoute(ProxyRoute $route, string $domain): ProcessOwnerContext
    {
        $ownership = $this->workspaceRouteOwnership->resolve($route);

        if ($ownership === null) {
            throw new GatewayApiException(
                "App hostname '{$domain}' workspace is not attached to its instance.",
                'validation_failed',
                [
                    'field' => 'app',
                    'value' => $domain,
                    'reason' => 'instance_required',
                ],
            );
        }

        $workspace = $ownership->workspace;
        $instance = $ownership->instance;
        $app = $ownership->app;

        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                "App hostname '{$domain}' workspace has no node.",
                'validation_failed',
                [
                    'field' => 'app',
                    'value' => $domain,
                ],
            );
        }

        return new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: $workspace,
            owner: $workspace,
            instance: $instance,
        );
    }
}
