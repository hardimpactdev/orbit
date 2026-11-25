<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Infrastructure;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Domain\T3AdminSession;
use Orbit\Extensions\Conn\Domain\T3EnvironmentDescriptor;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use SensitiveParameter;
use Throwable;

/**
 * Speaks the T3 server's environment HTTP API (`packages/contracts/src/environmentHttp.ts` in T3 Code).
 */
final readonly class HttpT3ServerClient implements T3ServerClient
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int TIMEOUT_SECONDS = 10;

    /** The orchestration routes refuse a request without the protocol version they speak. */
    private const string ORCHESTRATION_PROTOCOL_HEADER = 'x-t3-orchestration-protocol';

    public function describe(string $baseUrl): T3EnvironmentDescriptor
    {
        return $this->descriptor($baseUrl, $this->json($baseUrl, 'describe', fn (): Response => $this->request()->get($this->endpoint($baseUrl, '/.well-known/t3/environment'))));
    }

    public function describes(array $baseUrls, float $timeoutSeconds): array
    {
        if ($baseUrls === []) {
            return [];
        }
        $timeout = min($timeoutSeconds, self::TIMEOUT_SECONDS);
        $responses = Http::pool(function (Pool $pool) use ($baseUrls, $timeout): void {
            foreach ($baseUrls as $key => $url) {
                $this->request(null, $pool->as((string) $key), $timeout)->get($this->endpoint($url, '/.well-known/t3/environment'));
            }
        }, concurrency: 0);
        $descriptors = [];
        foreach ($baseUrls as $key => $url) {
            $response = $responses[(string) $key] ?? null;
            try {
                $descriptors[$key] = $response instanceof Response ? $this->descriptor($url, $this->json($url, 'describe', static fn (): Response => $response))
                    : T3ServerException::unreachable($url, $response instanceof Throwable ? $response : null);
            } catch (T3ServerException $exception) {
                $descriptors[$key] = $exception;
            }
        }

        return $descriptors;
    }

    /** @param array<array-key, mixed> $body */
    private function descriptor(string $baseUrl, array $body): T3EnvironmentDescriptor
    {
        $environmentId = $body['environmentId'] ?? null;
        $label = $body['label'] ?? null;
        $serverVersion = $body['serverVersion'] ?? null;

        if (! is_string($environmentId) || ! is_string($label) || ! is_string($serverVersion)) {
            throw T3ServerException::malformed($baseUrl, 'describe');
        }

        return new T3EnvironmentDescriptor($environmentId, $label, $serverVersion);
    }

    public function session(string $baseUrl, #[SensitiveParameter] string $adminSession): T3AdminSession
    {
        $body = $this->json($baseUrl, 'session', fn (): Response => $this->request($adminSession)
            ->get($this->endpoint($baseUrl, '/api/auth/session')));

        $authenticated = $body['authenticated'] ?? null;

        if ($authenticated === false) {
            throw T3ServerException::rejected($baseUrl, 'session');
        }

        $scopes = $body['scopes'] ?? null;
        $expiresAt = $body['expiresAt'] ?? null;

        if ($authenticated !== true || ! is_array($scopes) || ! array_is_list($scopes) || ! is_string($expiresAt)) {
            throw T3ServerException::malformed($baseUrl, 'session');
        }

        return new T3AdminSession(
            token: $adminSession,
            expiresAt: $this->timestamp($baseUrl, 'session', $expiresAt),
            scopes: array_values(array_filter($scopes, is_string(...))),
        );
    }

    public function shell(string $baseUrl, #[SensitiveParameter] string $adminSession): array
    {
        return $this->json($baseUrl, 'shell', fn (): Response => $this->request($adminSession)
            ->withHeaders([self::ORCHESTRATION_PROTOCOL_HEADER => '2'])
            ->get($this->endpoint($baseUrl, '/api/orchestration/shell')));
    }

    public function shells(#[SensitiveParameter] array $servers, float $timeoutSeconds): array
    {
        if ($servers === []) {
            return [];
        }
        $timeout = min($timeoutSeconds, self::TIMEOUT_SECONDS);
        $responses = Http::pool(function (Pool $pool) use ($servers, $timeout): void {
            foreach ($servers as $key => $server) {
                $this->request($server['session'], $pool->as((string) $key), $timeout)
                    ->withHeaders([self::ORCHESTRATION_PROTOCOL_HEADER => '2'])->get($this->endpoint($server['url'], '/api/orchestration/shell'));
            }
        }, concurrency: 0);
        $shells = [];
        foreach ($servers as $key => $server) {
            $response = $responses[(string) $key] ?? null;
            try {
                // Any failure of one request in the pool is that server's: it did not answer.
                $shells[$key] = $response instanceof Response ? $this->json($server['url'], 'shell', static fn (): Response => $response)
                    : T3ServerException::unreachable($server['url'], $response instanceof Throwable ? $response : null);
            } catch (T3ServerException $exception) {
                $shells[$key] = $exception;
            }
        }

        return $shells;
    }

    public function supportsThreadFiling(string $baseUrl): bool
    {
        try {
            $body = $this->json($baseUrl, 'health', fn (): Response => $this->request(timeout: 2.0)
                ->get($this->endpoint($baseUrl, '/health')));
        } catch (T3ServerException $error) {
            if (($error->details['status'] ?? null) === 404) {
                return false;
            }

            throw $error;
        }
        $version = $body['version'] ?? null;

        return ($body['server'] ?? null) === 'conn-server' && is_string($version)
            && preg_match('/\A\d+\.\d+\.\d+/', $version, $matches) === 1 && version_compare($matches[0], '0.5.2', '>=');
    }

    /** @return array<array-key, mixed>|null */
    public function threadProjection(string $baseUrl, #[SensitiveParameter] string $adminSession, string $threadId): ?array
    {
        try {
            $body = $this->json($baseUrl, 'thread snapshot', fn (): Response => $this->request($adminSession, timeout: 2.0)
                ->withHeaders([self::ORCHESTRATION_PROTOCOL_HEADER => '2'])
                ->get($this->endpoint($baseUrl, '/api/orchestration/threads/'.rawurlencode($threadId))));
        } catch (T3ServerException $error) {
            if (($error->details['status'] ?? null) === 404) {
                return null;
            }

            throw $error;
        }
        $projection = $body['projection'] ?? null;
        if (! is_array($projection) || ! is_array($projection['thread'] ?? null) || ! is_array($projection['messages'] ?? null)) {
            throw T3ServerException::malformed($baseUrl, 'thread snapshot');
        }

        return $projection;
    }

    private function request(#[SensitiveParameter] ?string $adminSession = null, ?PendingRequest $request = null, float $timeout = self::TIMEOUT_SECONDS): PendingRequest
    {
        $request = ($request ?? Http::createPendingRequest())->acceptJson()
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $timeout))
            ->timeout($timeout);

        return $adminSession === null ? $request : $request->withToken($adminSession);
    }

    private function endpoint(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').$path;
    }

    /**
     * @param  \Closure(): Response  $send
     * @return array<array-key, mixed>
     */
    private function json(string $baseUrl, string $operation, \Closure $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            throw T3ServerException::unreachable($baseUrl, $exception);
        }

        if (! $response->successful()) {
            $reason = $response->json('reason');

            throw T3ServerException::refused($baseUrl, $operation, $response->status(), is_string($reason) ? $reason : 'unknown');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw T3ServerException::malformed($baseUrl, $operation);
        }

        return $body;
    }

    private function timestamp(string $baseUrl, string $operation, string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw T3ServerException::malformed($baseUrl, $operation);
        }
    }
}
