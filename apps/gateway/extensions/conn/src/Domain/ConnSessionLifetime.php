<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnOwedGatewayRevoke;
use Orbit\Extensions\Conn\Models\ConnPairing;

/**
 * The until-revoked lifetime of the sessions Orbit keeps on a Conn Server ([Session lifetimes](/reference/conn#session-lifetimes)).
 * A server that passes the `/health` gate extends the Gateway session and the held sessions in place, so no device pairs again. A
 * peer-link session is never extended: its holder keeps the date it stored and drops the link then. A refusal keeps the session dated, and the hourly renewal keeps renewing it.
 */
final readonly class ConnSessionLifetime
{
    /** The one lifetime value Orbit sends: the server stores it as its about 10-year cap and still answers a date. */
    public const string UntilRevoked = 'until-revoked';

    /** The first Conn Server release that takes `until-revoked` and `extend`. */
    public const string MinimumVersion = '0.5.0';

    /** A session that ends later than this many days from now is long-lived: it is neither renewed nor extended. */
    public const int LongLivedDays = 365;

    /** The Conn Server 0.5.0 route that revokes the bearer's own session; `/api/auth/clients/revoke` refuses the caller's own. */
    public const string SelfRevokeRoute = '/api/auth/logout';

    /** What `extend` answers for a session the server no longer has. */
    private const string Gone = 'gone';

    public function __construct(private ConnServerLifetimes $server) {}

    public static function longLived(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(self::LongLivedDays);
    }

    /** At a server's start or wake: extends each dated session Orbit keeps there. A server that fails the gate keeps them dated. */
    public function migrate(ConnEnvironment $environment): void
    {
        $environment = $environment->fresh();
        if (! $environment instanceof ConnEnvironment || ! $environment->admin_session_expires_at->isFuture() || ! $this->server->untilRevoked($environment->url)) {
            return;
        }
        $longLived = self::longLived();
        if ($environment->admin_session_expires_at->lt($longLived)
            && ($expires = $this->extend($environment, $environment->admin_session, null, 'admin')) !== null) {
            // Only the session it extended: a renewal recorded meanwhile keeps its own end. A session the server no longer has has
            // ended, so the Instance's renewal issues a new one and a host server registers again.
            ConnEnvironment::query()->whereKey($environment->id)->where('admin_session_expires_at', $environment->admin_session_expires_at)
                ->update(['admin_session_expires_at' => $expires === self::Gone ? Carbon::now() : $expires]);
        }
        $held = ConnPairing::query()->where('conn_environment_id', $environment->id)->where('held', true)->whereNotNull('session')
            ->whereNull('withdrawn_at')->whereNull('revoked_at')->where('session_expires_at', '>', Carbon::now())->where('session_expires_at', '<', $longLived)->get();
        foreach ($held as $pairing) {
            $expires = $this->extend($environment, (string) $pairing->session, null, 'held');
            if ($expires instanceof CarbonImmutable) {
                ConnPairing::query()->whereKey($pairing->id)->whereNull('withdrawn_at')->whereNull('revoked_at')->update(['session_expires_at' => $expires]);
            } elseif ($expires === self::Gone) {
                // Nothing of it is left to renew or revoke; the Node's next read holds a new session.
                ConnPairing::query()->whereKey($pairing->id)->whereNull('withdrawn_at')->whereNull('revoked_at')
                    ->update(['session' => null, 'revoked_at' => Carbon::now()]);
            }
        }
    }

    /**
     * Revokes the Gateway session an unregistered host server still holds, once the server passes the gate; until then it stays owed.
     * The holder check and the logout run under the owed row's lock, which a registration with the same bearer takes before it drops
     * the row, so either the registration waits for the logout or the tick finds the row gone or held and sends none.
     */
    public function revokeOwed(ConnOwedGatewayRevoke $owed): bool
    {
        if (! $owed->admin_session_expires_at->isFuture()) {
            $owed->delete();

            return true;
        }
        if (! $this->server->untilRevoked($owed->url)) {
            return false;
        }

        return DB::transaction(function () use ($owed): bool {
            $owed = ConnOwedGatewayRevoke::query()->whereKey($owed->id)->lockForUpdate()->first();
            if (! $owed instanceof ConnOwedGatewayRevoke) {
                return true;
            }
            // A server registered again with the same bearer uses this session, so it is no longer owed; never revoke it.
            if (ConnEnvironment::query()->get()->contains(fn (ConnEnvironment $environment): bool => hash_equals($environment->admin_session, $owed->admin_session))) {
                $owed->delete();

                return true;
            }
            try {
                $this->server->revokeSelf($owed->url, $owed->admin_session);
            } catch (T3ServerException $error) {
                // A 401: an earlier logout revoked it already, or the server no longer has it.
                if ($error->errorCode !== 'conn.session_rejected') {
                    Log::warning('conn.gateway_revoke_failed', ['environment_id' => $owed->environment_id, 'error_code' => $error->errorCode]);

                    return false;
                }
            }
            $owed->delete();

            return true;
        });
    }

    /**
     * The new end, Gone for a session the server no longer has, or null for one that stays dated. The gate already proved the server
     * takes `extend`, so its 404 means the session is gone, not an old server.
     *
     * @return CarbonImmutable|self::Gone|null
     */
    private function extend(ConnEnvironment $environment, string $bearer, ?string $sessionId, string $session): CarbonImmutable|string|null
    {
        try {
            return $this->server->extend($environment->url, $bearer, $sessionId);
        } catch (T3ServerException $error) {
            $status = $error->details['status'] ?? null;
            $context = ['environment_id' => $environment->environment_id, 'session' => $session, 'error_code' => $error->errorCode, 'status' => $status];
            if ($status === 404) {
                Log::info('conn.session_extend_gone', $context);

                return self::Gone;
            }
            // 409 operation_forbidden: a T3-carried session, which its signed token bounds; it stays dated and renewed. Anything else,
            // as a 400 invalid_session_ttl, is an error to report; the session stays dated meanwhile.
            Log::log($status === 409 ? 'info' : 'error', 'conn.session_extend_refused', [...$context, 'reason' => $error->details['reason'] ?? null]);

            return null;
        }
    }
}
