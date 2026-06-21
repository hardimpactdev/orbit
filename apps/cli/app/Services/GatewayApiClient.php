<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ForkedFrameTicker;
use Throwable;

/**
 * Per decision D2: the CLI never sends a bearer identity. Production gateway API identity
 * comes from WireGuard peer resolution (X-Orbit-WireGuard-Ip via orbit-caddy, or direct
 * peer address when caddy is not in the path). The CLI never spoofs that header.
 *
 * Per decision D15: when the gateway is not reachable because WireGuard is down or no peer
 * route exists, calls fail with the distinct error code `gateway_unreachable_wireguard`,
 * not the generic `gateway_unavailable`.
 */
final readonly class GatewayApiClient
{
    private const int CurlMultiSelectTimeoutSeconds = 0;

    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
        private ?string $caPemPath = null,
    ) {}

    public function withMinimumTimeout(int $seconds): self
    {
        if ($seconds <= $this->timeout) {
            return $this;
        }

        return new self(
            baseUrl: $this->baseUrl,
            timeout: $seconds,
            caPemPath: $this->caPemPath,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->get($this->path($path), $query)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->post($this->path($path), $payload)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postWithIdleTicks(string $path, array $payload = []): array
    {
        if (! ForkedFrameTicker::hasIdleCallback()) {
            return $this->post($path, $payload);
        }

        return $this->decode(
            $this->requestWithIdleTicks(
                fn (CurlMultiHandler $handler) => $this->pendingRequestForDispatch($handler)->post($this->path($path), $payload),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(string $path, array $payload = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->put($this->path($path), $payload)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patch(string $path, array $payload = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->patch($this->path($path), $payload)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function delete(string $path, array $payload = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->delete($this->path($path), $payload)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function streamEvents(string $path, array $payload, callable $onEvent): int
    {
        throw new GatewayApiException('Gateway streaming requests are not implemented yet.');
    }

    private function pendingRequest(): PendingRequest
    {
        $baseUrl = $this->normalizedBaseUrl();

        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        if ($this->shouldVerifyAgainstGatewayCa()) {
            $request = $request->withOptions(['verify' => $this->caPemPath]);
        }

        return $request;
    }

    /**
     * Verify the gateway TLS certificate against the locally configured gateway CA when it is
     * present on disk. This mirrors VerifyGatewayIdentity so the self-contained binary's bundled
     * CA store does not have to recognize the gateway's private CA. When no CA PEM path is
     * configured, the default HTTP client verification behavior is left unchanged.
     */
    private function shouldVerifyAgainstGatewayCa(): bool
    {
        return is_string($this->caPemPath)
            && $this->caPemPath !== ''
            && is_file($this->caPemPath);
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function path(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function request(callable $callback): Response
    {
        try {
            $response = $callback();
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $response;
    }

    /**
     * @param  callable(CurlMultiHandler): (Response|PromiseInterface)  $callback
     */
    private function requestWithIdleTicks(callable $callback): Response
    {
        $handler = new CurlMultiHandler([
            'select_timeout' => self::CurlMultiSelectTimeoutSeconds,
        ]);

        return $this->finalizeResponse($this->waitForResponseWithIdleTicks(fn () => $callback($handler), $handler));
    }

    /**
     * @param  callable(): (Response|PromiseInterface)  $callback
     */
    private function waitForResponseWithIdleTicks(callable $callback, CurlMultiHandler $handler): Response
    {
        try {
            $result = $callback();

            if ($result instanceof Response) {
                return $result;
            }

            if ($result instanceof LazyPromise) {
                $result = $result->buildPromise();
            }

            $response = null;
            $rejection = null;

            $result->then(
                function (Response $resolved) use (&$response): void {
                    $response = $resolved;
                },
                function (mixed $reason) use (&$rejection): void {
                    $rejection = $reason;
                },
            );

            while ($response === null && $rejection === null) {
                ForkedFrameTicker::invokeIdleCallback();
                $handler->tick();
                usleep(ForkedFrameTicker::idleIntervalMicroseconds());
            }

            if ($rejection instanceof Throwable) {
                throw $rejection;
            }

            if (! $response instanceof Response) {
                throw new GatewayApiException('Gateway request did not return a response.');
            }

            return $response;
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }
    }

    private function finalizeResponse(Response $response): Response
    {
        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $response;
    }

    private function pendingRequestForDispatch(CurlMultiHandler $handler): PendingRequest
    {
        return $this->pendingRequest()
            ->async()
            ->setHandler($handler);
    }

    /**
     * Distinguish a WireGuard-unreachable failure from a generic gateway-down failure so the
     * CLI can surface `gateway_unreachable_wireguard` per D15. A best-effort signal: connection
     * timeouts or DNS/host-unreachable errors when the URL is a WireGuard address point at WG
     * being down. Anything else stays as the generic gateway-unavailable error.
     */
    private function classifyNetworkError(ConnectionException $exception): GatewayApiException
    {
        $message = strtolower($exception->getMessage());

        $isWireGuardReachabilityFailure = str_contains($message, 'timed out')
            || str_contains($message, 'no route to host')
            || str_contains($message, 'network is unreachable')
            || str_contains($message, 'could not resolve host');

        if ($isWireGuardReachabilityFailure) {
            return GatewayApiException::wireguardUnreachable($exception);
        }

        return GatewayApiException::networkError($exception);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        return $decoded;
    }
}
