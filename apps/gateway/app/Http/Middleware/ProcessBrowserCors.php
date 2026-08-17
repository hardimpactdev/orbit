<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\App;
use App\Models\ProxyRoute;
use App\Services\Processes\ProcessAppHostnameResolver;
use App\Services\Proxy\AppProxyRouteTargetResolver;
use App\Services\Proxy\WorkspaceProxyRouteOwnershipResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser CORS Origin admission for existing process list/lifecycle routes.
 *
 * Global exact-path middleware: no-op for every other path. Preflight and
 * Origin-bearing GET/POST only validate that a registered app/workspace
 * proxy_routes.domain Origin matches the requested `app` hostname and uses a
 * default scheme/port (http/:80 or https/:443). CORS never establishes caller
 * identity and never reads peer-IP headers.
 *
 * Auth remains the CLI model: WireGuardIdentity maps the actual WireGuard peer
 * source IP to a node, then RequireGrantPermission and target-node
 * authorization run. Clients must not send bearer tokens or peer-IP identity
 * headers; those headers are never CORS-allowed. CORS headers wrap responses
 * so grant/identity errors stay readable to the browser.
 */
/** @mago-expect lint:cyclomatic-complexity */
/** @mago-expect lint:kan-defect */
/** @mago-expect lint:too-many-methods */
final readonly class ProcessBrowserCors
{
    private const array AllowedMethods = ['GET', 'POST'];

    /**
     * Browser-visible request headers only. Optional X-Orbit-Client is a
     * non-identity label. Peer-IP identity headers are never client-sent and
     * must never be listed as CORS-allowed.
     */
    private const array AllowedHeaders = [
        'Accept',
        'Content-Type',
        'Last-Event-ID',
        'X-Orbit-Client',
        'X-Correlation-Id',
    ];

    /** Client-supplied peer-IP headers are forbidden in CORS preflight. */
    private const array ForbiddenPeerIpHeaders = [
        'x-orbit-wireguard-ip',
        'x-orbit-e2e-wireguard-ip',
    ];

    private const array ProcessPaths = [
        'api/processes',
        'api/processes/stream',
        'api/processes/start',
        'api/processes/stop',
        'api/processes/restart',
    ];

    public function __construct(
        private ProcessAppHostnameResolver $appHostnames,
        private AppProxyRouteTargetResolver $appRouteTargets,
        private WorkspaceProxyRouteOwnershipResolver $workspaceRouteOwnership,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isProcessBrowserSurface($request)) {
            return $this->toResponse($next($request));
        }

        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request, $origin);
        }

        if (! is_string($origin) || trim($origin) === '') {
            return $this->toResponse($next($request));
        }

        $originHost = $this->appHostnames->hostnameFromBrowserOrigin($origin);

        if ($originHost === null || ! $this->isRegisteredAppOrWorkspaceDomain($originHost)) {
            return $this->reject(
                'Browser Origin is not a registered app or workspace proxy domain.',
                [
                    'field' => 'origin',
                    'value' => $origin,
                ],
            );
        }

        $appTarget = $this->requestedAppTarget($request);

        if ($appTarget === null) {
            return $this->reject(
                'Browser process requests that send Origin must include the app target.',
                [
                    'field' => 'app',
                    'origin' => $originHost,
                ],
            );
        }

        try {
            $normalizedApp = $this->appHostnames->assertStrictHostname($appTarget);
        } catch (GatewayApiException $exception) {
            return $this->reject(
                $exception->getMessage(),
                $exception->errorMeta() !== [] ? $exception->errorMeta() : ['field' => 'app'],
            );
        }

        if ($normalizedApp !== $originHost) {
            return $this->reject(
                'Browser Origin does not match the requested app target.',
                [
                    'field' => 'origin',
                    'origin' => $originHost,
                    'app' => $normalizedApp,
                ],
            );
        }

        // Call through WireGuardIdentity + grants so those errors keep CORS headers.
        return $this->withActualCorsHeaders($this->toResponse($next($request)), $origin);
    }

    private function toResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        return response('', 500);
    }

    private function isProcessBrowserSurface(Request $request): bool
    {
        return in_array(trim($request->path(), '/'), self::ProcessPaths, true);
    }

    private function preflight(Request $request, ?string $origin): Response
    {
        if (! is_string($origin) || trim($origin) === '') {
            return $this->reject(
                'Browser CORS preflight requires a registered Origin.',
                ['field' => 'origin'],
            );
        }

        $originHost = $this->appHostnames->hostnameFromBrowserOrigin($origin);

        if ($originHost === null || ! $this->isRegisteredAppOrWorkspaceDomain($originHost)) {
            return $this->reject(
                'Browser Origin is not a registered app or workspace proxy domain.',
                [
                    'field' => 'origin',
                    'value' => $origin,
                ],
            );
        }

        $requestedMethod = $request->headers->get('Access-Control-Request-Method');

        if (
            ! is_string($requestedMethod)
            || ! in_array(mb_strtoupper(trim($requestedMethod)), self::AllowedMethods, true)
        ) {
            return $this->reject(
                'Browser CORS preflight method is not allowed for process routes.',
                [
                    'field' => 'access-control-request-method',
                    'value' => $requestedMethod,
                ],
            );
        }

        $requestedHeaders = $this->requestedHeaders($request);

        foreach ($requestedHeaders as $header) {
            $normalized = mb_strtolower($header);

            if (in_array($normalized, self::ForbiddenPeerIpHeaders, true)) {
                return $this->reject(
                    'Browser CORS preflight must not request peer-IP identity headers.',
                    [
                        'field' => 'access-control-request-headers',
                        'value' => $header,
                    ],
                );
            }

            if (! $this->isAllowedHeader($normalized)) {
                return $this->reject(
                    'Browser CORS preflight header is not allowed for process routes.',
                    [
                        'field' => 'access-control-request-headers',
                        'value' => $header,
                    ],
                );
            }
        }

        return $this->withPreflightCorsHeaders(response('', 204), $origin);
    }

    /**
     * @return list<string>
     */
    private function requestedHeaders(Request $request): array
    {
        $raw = $request->headers->get('Access-Control-Request-Headers');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $headers = [];

        foreach (explode(',', $raw) as $part) {
            $header = trim($part);

            if ($header !== '') {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    private function isAllowedHeader(string $normalizedHeader): bool
    {
        return array_any(
            self::AllowedHeaders,
            static fn (string $allowed): bool => mb_strtolower($allowed) === $normalizedHeader,
        );
    }

    private function isRegisteredAppOrWorkspaceDomain(string $domain): bool
    {
        $route = ProxyRoute::query()
            ->with(['instance.app', 'workspace.instance.app'])
            ->where('domain', $domain)
            ->where(static function ($query): void {
                $query
                    ->where(static function ($app): void {
                        $app
                            ->where('owner_type', 'app')
                            ->where('kind', 'app');
                    })
                    ->orWhere(static function ($workspace): void {
                        $workspace
                            ->where('owner_type', 'workspace')
                            ->where('kind', 'workspace');
                    });
            })
            ->first();

        if (! $route instanceof ProxyRoute) {
            return false;
        }

        if ($route->owner_type === 'app' && $route->kind === 'app') {
            return $this->appRouteTargets->appForRoute($route) instanceof App;
        }

        return $this->workspaceRouteOwnership->resolve($route) !== null;
    }

    private function requestedAppTarget(Request $request): ?string
    {
        $value = $request->isMethod('GET')
            ? $request->query('app')
            : $request->input('app');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function reject(string $message, array $meta): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], 403);
    }

    private function withActualCorsHeaders(Response $response, string $origin): Response
    {
        return $this->applyCorsHeaders($response, $origin, ['Origin']);
    }

    private function withPreflightCorsHeaders(Response $response, string $origin): Response
    {
        return $this->applyCorsHeaders(
            $response,
            $origin,
            [
                'Origin',
                'Access-Control-Request-Method',
                'Access-Control-Request-Headers',
            ],
        );
    }

    /**
     * @param  list<string>  $vary
     */
    private function applyCorsHeaders(Response $response, string $origin, array $vary): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', self::AllowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', self::AllowedHeaders));
        $response->headers->set('Access-Control-Max-Age', '600');

        $merged = $response->headers->get('Vary');

        foreach ($vary as $value) {
            $merged = $this->mergeVary($merged, $value);
        }

        $response->headers->set('Vary', $merged);

        return $response;
    }

    private function mergeVary(?string $existing, string $value): string
    {
        if (! is_string($existing) || trim($existing) === '') {
            return $value;
        }

        $parts = array_map(trim(...), explode(',', $existing));

        if (! in_array($value, $parts, true)) {
            $parts[] = $value;
        }

        return implode(', ', $parts);
    }
}
