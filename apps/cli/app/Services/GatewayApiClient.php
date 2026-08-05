<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Progress\ForkedFrameTicker;
use ReflectionObject;
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
    private const int IdleRequestPollMicroseconds = 100_000;

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
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest($headers)->get($this->path($path), $query)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], array $headers = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest($headers)->post($this->path($path), $payload)),
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

        if (! $this->httpClientIsFaked()) {
            if ($this->canForkIdleRequest()) {
                return $this->postWithIdleTicksInChild($path, $payload);
            }

            if ($this->canCurlIdleRequest()) {
                return $this->postWithCurlIdleTicks($path, $payload);
            }
        }

        return $this->decode(
            $this->request(fn () => $this->pendingRequestWithIdleProgress()->post($this->path($path), $payload)),
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

    /**
     * @param  array<string, string>  $headers
     */
    private function pendingRequest(array $headers = []): PendingRequest
    {
        $baseUrl = $this->normalizedBaseUrl();

        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

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
        return is_string($this->caPemPath) && $this->caPemPath !== '' && is_file($this->caPemPath);
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

    private function pendingRequestWithIdleProgress(): PendingRequest
    {
        return $this->pendingRequest()
            ->withOptions([
                'progress' => static function (): void {
                    ForkedFrameTicker::invokeIdleCallback();
                },
            ]);
    }

    private function canForkIdleRequest(): bool
    {
        if (getenv('ORBIT_GATEWAY_IDLE_POST_DISABLE_FORK') === '1') {
            return false;
        }

        return (
            function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair')
        );
    }

    private function canCurlIdleRequest(): bool
    {
        return (
            function_exists('curl_init')
            && function_exists('curl_multi_init')
            && function_exists('curl_multi_exec')
            && function_exists('curl_multi_select')
        );
    }

    /**
     * Keep the idle callback moving in self-contained binaries that do not ship
     * the pcntl extension. Guzzle's progress hook is too sparse while a POST is
     * waiting on response headers, so drive the request through cURL multi and
     * poll it on the progress ticker cadence.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postWithCurlIdleTicks(string $path, array $payload): array
    {
        $curl = curl_init($this->normalizedBaseUrl().$this->path($path));

        if ($curl === false) {
            throw $this->classifyNetworkError(new ConnectionException('Unable to initialize gateway request.'));
        }

        $multi = curl_multi_init();
        $body = '';
        $requestBody = $payload === []
            ? '{}'
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 10),
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($this->shouldVerifyAgainstGatewayCa()) {
            curl_setopt($curl, CURLOPT_CAINFO, $this->caPemPath);
        }

        curl_multi_add_handle($multi, $curl);

        try {
            $running = null;

            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

                if ($multiStatus !== CURLM_OK) {
                    throw $this->classifyNetworkError(new ConnectionException(curl_multi_strerror($multiStatus)));
                }

                if ($running > 0) {
                    ForkedFrameTicker::invokeIdleCallback();

                    if (curl_multi_select($multi, 0.0) === -1) {
                        usleep(250);
                    }

                    usleep(min(ForkedFrameTicker::idleIntervalMicroseconds(), self::IdleRequestPollMicroseconds));
                }
            } while ($running > 0);

            $curlErrorNumber = curl_errno($curl);

            if ($curlErrorNumber !== 0) {
                throw $this->classifyNetworkError(new ConnectionException(curl_error($curl)));
            }

            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if ($statusCode >= 400) {
                throw GatewayApiException::httpError($statusCode, $body);
            }

            return $this->decodeBody($body);
        } finally {
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
        }
    }

    /**
     * Run the blocking HTTP request in a child process while the parent keeps
     * invoking the idle callback on cadence. This avoids Guzzle's CurlMultiHandler
     * shutdown path on macOS/libcurl while preserving live progress ticks.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postWithIdleTicksInChild(string $path, array $payload): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            return $this->decode(
                $this->request(fn () => $this->pendingRequestWithIdleProgress()->post($this->path($path), $payload)),
            );
        }

        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);

            return $this->decode(
                $this->request(fn () => $this->pendingRequestWithIdleProgress()->post($this->path($path), $payload)),
            );
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->writeIdleChildResult($childSocket, $path, $payload);
            fclose($childSocket);
            exit(0);
        }

        fclose($childSocket);
        stream_set_blocking($parentSocket, false);

        $buffer = '';

        try {
            do {
                $chunk = stream_get_contents($parentSocket);

                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                }

                $result = pcntl_waitpid($pid, $status, WNOHANG);

                if ($result === $pid || $result === -1) {
                    break;
                }

                ForkedFrameTicker::invokeIdleCallback();
                usleep(min(ForkedFrameTicker::idleIntervalMicroseconds(), self::IdleRequestPollMicroseconds));
            } while (true);

            $remaining = stream_get_contents($parentSocket);

            if (is_string($remaining) && $remaining !== '') {
                $buffer .= $remaining;
            }
        } finally {
            fclose($parentSocket);
            pcntl_waitpid($pid, $status, WNOHANG);
        }

        return $this->decodeIdleChildResult($buffer);
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $payload
     */
    private function writeIdleChildResult(mixed $socket, string $path, array $payload): void
    {
        try {
            $result = [
                'ok' => true,
                'data' => $this->post($path, $payload),
            ];
        } catch (GatewayApiException $exception) {
            $result = [
                'ok' => false,
                'type' => 'gateway',
                'message' => $exception->getMessage(),
                'failure_kind' => $exception->failureKind()->name,
                'status_code' => $exception->statusCode(),
                'gateway_error' => $exception->hasGatewayError()
                    ? [
                        'code' => $exception->gatewayErrorCode(),
                        'message' => $exception->gatewayErrorMessage(),
                        'meta' => $exception->gatewayErrorMeta(),
                        'data' => $exception->gatewayErrorData(),
                    ] : null,
            ];
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'type' => 'generic',
                'message' => $exception->getMessage(),
            ];
        }

        fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIdleChildResult(string $buffer): array
    {
        $decoded = json_decode($buffer, associative: true);

        if (! is_array($decoded)) {
            throw new GatewayApiException('Gateway request child did not return a valid response.');
        }

        if (($decoded['ok'] ?? false) === true && is_array($decoded['data'] ?? null)) {
            return $decoded['data'];
        }

        if (($decoded['type'] ?? null) === 'gateway') {
            throw $this->gatewayExceptionFromChildResult($decoded);
        }

        $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Gateway request failed.';

        throw new GatewayApiException($message);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function gatewayExceptionFromChildResult(array $result): GatewayApiException
    {
        $failureKindName = is_string($result['failure_kind'] ?? null)
            ? $result['failure_kind']
            : GatewayApiFailureKind::Generic->name;
        $failureKind = GatewayApiFailureKind::{$failureKindName};
        $statusCode = is_int($result['status_code'] ?? null) ? $result['status_code'] : null;
        $message = is_string($result['message'] ?? null) ? $result['message'] : 'Gateway request failed.';
        $gatewayError = is_array($result['gateway_error'] ?? null) ? $result['gateway_error'] : null;
        $body = null;

        if ($gatewayError !== null && is_string($gatewayError['code'] ?? null)) {
            $envelope = JsonEnvelope::failure(
                $gatewayError['code'],
                is_string($gatewayError['message'] ?? null) ? $gatewayError['message'] : 'Gateway request failed.',
                is_array($gatewayError['meta'] ?? null) ? $gatewayError['meta'] : [],
            );

            if (is_array($gatewayError['data'] ?? null) && $gatewayError['data'] !== []) {
                $envelope['error']['data'] = $gatewayError['data'];
            }

            $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return new GatewayApiException($message, $failureKind, $statusCode, $body);
    }

    private function httpClientIsFaked(): bool
    {
        $factory = Http::getFacadeRoot();

        if (! is_object($factory)) {
            return false;
        }

        $reflection = new ReflectionObject($factory);

        if (! $reflection->hasProperty('stubCallbacks')) {
            return false;
        }

        $property = $reflection->getProperty('stubCallbacks');
        $callbacks = $property->getValue($factory);

        return is_countable($callbacks) && count($callbacks) > 0;
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

        $isWireGuardReachabilityFailure =
            str_contains($message, 'timed out')
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
        return $this->decodeBody($response->body());
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, associative: true);

        if (! is_array($decoded)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        return $decoded;
    }
}
