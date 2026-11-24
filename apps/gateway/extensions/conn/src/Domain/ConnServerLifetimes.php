<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * The Conn Server's session lifetime calls ([Session lifetimes](/reference/conn#session-lifetimes)). Every method throws
 * T3ServerException when the server is unreachable or refuses the call.
 */
interface ConnServerLifetimes
{
    /** GET /health: whether the server is a Conn Server that takes `until-revoked`. A TypeScript T3 server or an older one does not. */
    public function untilRevoked(string $baseUrl): bool;

    /** POST /api/auth/clients/extend: the new end of the bearer's own session, or with `$sessionId` of that session. */
    public function extend(string $baseUrl, #[SensitiveParameter] string $bearer, ?string $sessionId = null): CarbonImmutable;

    /**
     * GET /api/auth/clients: the server's client sessions.
     *
     * @return list<array{session_id: string, subject: string, label: string, expires_at: CarbonImmutable}>
     */
    public function clients(string $baseUrl, #[SensitiveParameter] string $adminSession): array;

    /** POST /api/auth/logout: revokes the bearer's own session. */
    public function revokeSelf(string $baseUrl, #[SensitiveParameter] string $bearer): void;
}
