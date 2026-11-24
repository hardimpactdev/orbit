<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Models\InstanceRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnOwedGatewayRevoke;
use Orbit\Extensions\Conn\Models\ConnPairing;

/**
 * The until-revoked lifetime of the sessions Orbit keeps on a Conn Server ([Session lifetimes](/reference/conn#session-lifetimes)).
 * A server that passes the `/health` gate extends the Gateway session, the held sessions and the Conn peer-link sessions it holds
 * in place, so no device pairs again. A refusal keeps the session dated, and the hourly renewal keeps renewing it.
 */
final readonly class ConnSessionLifetime
{
    /** The one lifetime value Orbit sends: the server stores it as its about 10-year cap and still answers a date. */
    public const string UntilRevoked = 'until-revoked';

    /** The first Conn Server release that takes `until-revoked` and `extend`. */
    public const string MinimumVersion = '0.5.0';

    /** A session that ends later than this many days from now is long-lived: it is neither renewed nor extended. */
    public const int LongLivedDays = 365;

    /** The MCP client label of a Conn peer link, `Conn · <holder>`. */
    public const string PeerLabelPrefix = 'Conn · ';

    /** PENDING: the Conn Server 0.5.0 route that revokes the bearer's own session; its path is not agreed yet. */
    public const string SelfRevokeRoute = '/api/auth/session/revoke';

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
            && ($expires = $this->extend($environment, $environment->admin_session, null, 'admin')) instanceof CarbonImmutable) {
            // Only the session it extended: a renewal recorded meanwhile keeps its own end.
            ConnEnvironment::query()->whereKey($environment->id)->where('admin_session_expires_at', $environment->admin_session_expires_at)
                ->update(['admin_session_expires_at' => $expires]);
        }
        $held = ConnPairing::query()->where('conn_environment_id', $environment->id)->where('held', true)->whereNotNull('session')
            ->whereNull('withdrawn_at')->whereNull('revoked_at')->where('session_expires_at', '>', Carbon::now())->where('session_expires_at', '<', $longLived)->get();
        foreach ($held as $pairing) {
            if (($expires = $this->extend($environment, (string) $pairing->session, null, 'held')) instanceof CarbonImmutable) {
                ConnPairing::query()->whereKey($pairing->id)->whereNull('withdrawn_at')->whereNull('revoked_at')->update(['session_expires_at' => $expires]);
            }
        }
        try {
            $clients = $this->server->clients($environment->url, $environment->admin_session);
        } catch (T3ServerException $error) {
            Log::warning('conn.session_extend_failed', ['environment_id' => $environment->environment_id, 'session' => 'peer', 'error_code' => $error->errorCode]);

            return;
        }
        foreach ($clients as $client) {
            if ($client['subject'] === 'mcp-client' && str_starts_with($client['label'], self::PeerLabelPrefix) && $client['expires_at']->lt($longLived)) {
                $this->extend($environment, $environment->admin_session, $client['session_id'], 'peer');
            }
        }
    }

    /** Revokes the Gateway session an unregistered host server still holds, once the server passes the gate; until then it stays owed. */
    public function revokeOwed(ConnOwedGatewayRevoke $owed): bool
    {
        if (! $owed->admin_session_expires_at->isFuture()) {
            $owed->delete();

            return true;
        }
        if (! $this->server->untilRevoked($owed->url)) {
            return false;
        }
        try {
            $this->server->revokeSelf($owed->url, $owed->admin_session);
        } catch (T3ServerException $error) {
            // A session the server no longer accepts is gone already.
            if ($error->errorCode !== 'conn.session_rejected') {
                Log::warning('conn.gateway_revoke_failed', ['environment_id' => $owed->environment_id, 'error_code' => $error->errorCode]);

                return false;
            }
        }
        $owed->delete();

        return true;
    }

    /** Whether the Instance of a container server is asleep; a sleeping one is migrated at its wake. */
    public static function asleep(ConnEnvironment $environment): bool
    {
        return $environment->instance_id !== null && InstanceRuntime::query()->where('instance_id', $environment->instance_id)
            ->where('power_state', InstanceRuntimePowerState::Stopped->value)->exists();
    }

    private function extend(ConnEnvironment $environment, string $bearer, ?string $sessionId, string $session): ?CarbonImmutable
    {
        try {
            return $this->server->extend($environment->url, $bearer, $sessionId);
        } catch (T3ServerException $error) {
            // 404: no extend route; 409 operation_forbidden: a T3-carried session. Either way it stays dated and renewed.
            $status = $error->details['status'] ?? null;
            Log::log(in_array($status, [404, 409], true) ? 'info' : 'warning', 'conn.session_extend_refused',
                ['environment_id' => $environment->environment_id, 'session' => $session, 'error_code' => $error->errorCode, 'status' => $status]);

            return null;
        }
    }
}
