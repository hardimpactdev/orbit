<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Infrastructure;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Domain\ConnServerLifetimes;
use Orbit\Extensions\Conn\Domain\ConnSessionLifetime;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use SensitiveParameter;
use Throwable;

/** Speaks the Conn Server's session lifetime routes (conn-server `http.rs`, from 0.5.0). */
final readonly class HttpConnServerLifetimes implements ConnServerLifetimes
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int TIMEOUT_SECONDS = 10;

    public function untilRevoked(string $baseUrl): bool
    {
        // Any failure of the gate, as a TypeScript server or an unreachable one gives, keeps the sessions dated.
        try {
            $response = $this->request()->get($this->endpoint($baseUrl, '/health'));
        } catch (Throwable) {
            return false;
        }
        $server = $response->successful() ? $response->json('server') : null;
        $version = $response->successful() ? $response->json('version') : null;

        return $server === 'conn-server' && is_string($version) && preg_match('/\A\d+\.\d+\.\d+/', $version, $match) === 1
            && version_compare($match[0], ConnSessionLifetime::MinimumVersion, '>=');
    }

    public function extend(string $baseUrl, #[SensitiveParameter] string $bearer, ?string $sessionId = null): CarbonImmutable
    {
        $body = $this->json($baseUrl, 'extend', fn (): Response => $this->request($bearer)->post($this->endpoint($baseUrl, '/api/auth/clients/extend'),
            [...$sessionId === null ? [] : ['sessionId' => $sessionId], 'sessionTtl' => ConnSessionLifetime::UntilRevoked]));
        $expiresAt = $body['expiresAt'] ?? null;
        if (! is_string($expiresAt) || ($sessionId !== null && ($body['sessionId'] ?? null) !== $sessionId)) {
            throw T3ServerException::malformed($baseUrl, 'extend');
        }

        return $this->timestamp($baseUrl, 'extend', $expiresAt);
    }

    public function clients(string $baseUrl, #[SensitiveParameter] string $adminSession): array
    {
        $body = $this->json($baseUrl, 'clients', fn (): Response => $this->request($adminSession)->get($this->endpoint($baseUrl, '/api/auth/clients')));
        $rows = array_is_list($body) ? $body : ($body['clientSessions'] ?? $body['clients'] ?? null);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw T3ServerException::malformed($baseUrl, 'clients');
        }
        $clients = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['sessionId'] ?? null) : null;
            $expiresAt = is_array($row) ? ($row['expiresAt'] ?? null) : null;
            if (! is_string($id) || ! is_string($expiresAt)) {
                throw T3ServerException::malformed($baseUrl, 'clients');
            }
            $subject = $row['subject'] ?? null;
            $label = is_array($row['client'] ?? null) ? ($row['client']['label'] ?? null) : null;
            $clients[] = ['session_id' => $id, 'subject' => is_string($subject) ? $subject : '', 'label' => is_string($label) ? $label : '',
                'expires_at' => $this->timestamp($baseUrl, 'clients', $expiresAt)];
        }

        return $clients;
    }

    public function revokeSelf(string $baseUrl, #[SensitiveParameter] string $bearer): void
    {
        // No body: the bearer names the session. A 401 after an earlier success is refused as `conn.session_rejected`.
        $this->json($baseUrl, 'logout', fn (): Response => $this->request($bearer)->send('POST', $this->endpoint($baseUrl, ConnSessionLifetime::SelfRevokeRoute)), empty: true);
    }

    private function request(#[SensitiveParameter] ?string $bearer = null): PendingRequest
    {
        $request = Http::acceptJson()->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS);

        return $bearer === null ? $request : $request->withToken($bearer);
    }

    private function endpoint(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').$path;
    }

    /**
     * @param  \Closure(): Response  $send
     * @return array<array-key, mixed>
     */
    private function json(string $baseUrl, string $operation, \Closure $send, bool $empty = false): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            throw T3ServerException::unreachable($baseUrl, $exception);
        }
        if (! $response->successful()) {
            $reason = $response->json('reason') ?? $response->json('code') ?? $response->json('error');

            throw T3ServerException::refused($baseUrl, $operation, $response->status(), is_string($reason) ? $reason : 'unknown');
        }
        $body = $response->json();
        if (! is_array($body)) {
            if ($empty) {
                return [];
            }

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
