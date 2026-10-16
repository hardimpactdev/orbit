<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Extensions\Contributions;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WireGuard\VpnSettings;
use LogicException;

final class ReservedPrivateHostname
{
    /** The private name space. Every reserved name lies under it, and no Cluster or Node TLD may cover it. */
    public const string PrivateSpace = 'orbit';

    public const string Gateway = 'gateway.'.self::PrivateSpace;

    public const string Metrics = 'metrics.'.self::PrivateSpace;

    /** @var list<string> */
    public const array CoreNames = [self::Gateway, self::Metrics, WebSocketHostname::Value, AnalyticsHostname::Value];

    /** A lowercase DNS name in the private space: one or more labels, then the private space. */
    private const string OrbitName = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'.self::PrivateSpace.'\z/';

    /**
     * Core's names, then the names that extensions reserve.
     *
     * @return list<string>
     *
     * @throws LogicException
     */
    public static function names(): array
    {
        return [...self::CoreNames, ...self::contributed()];
    }

    /**
     * The names that extensions reserve, in provider order. Each must be a lowercase DNS name under `.orbit` that
     * core and the other contributions do not reserve. Anything else is a wiring error. It throws, so a contribution
     * never takes or repeats a reserved name unseen, and never reaches a dnsmasq record with a comma or a newline.
     *
     * @return list<string>
     *
     * @throws LogicException
     */
    public static function contributed(): array
    {
        $names = [];

        foreach (app(Contributions::class)->of(ContributedReservedHostnames::class) as $contribution) {
            foreach ($contribution->hostnames() as $hostname) {
                $problem = self::problem($hostname, $names);

                if ($problem !== null) {
                    throw new LogicException('The contribution ['.$contribution::class."] reserves the hostname [{$hostname}], which {$problem}.");
                }

                $names[] = $hostname;
            }
        }

        return $names;
    }

    /**
     * Why a contributed hostname cannot be reserved, or null when it can.
     *
     * @param  list<string>  $contributed  The names that earlier contributions reserve.
     */
    private static function problem(string $hostname, array $contributed): ?string
    {
        if (preg_match('/[,\s]/', $hostname) === 1) {
            return 'contains a comma or whitespace';
        }

        if ($hostname !== strtolower($hostname)) {
            return 'is not lowercase';
        }

        if (strlen($hostname) > 253 || preg_match(self::OrbitName, $hostname) !== 1) {
            return 'is not a DNS name under .'.self::PrivateSpace;
        }

        if (in_array($hostname, self::CoreNames, true) || in_array($hostname, $contributed, true)) {
            return 'is already reserved';
        }

        return null;
    }

    /**
     * Whether a normalized Cluster or Node TLD covers private names. Private DNS answers every name under a TLD, so
     * no TLD may be the private space, where the reserved names live, or the configured private VPN domain or an
     * ancestor of it, where the Node names live.
     */
    public static function isPrivateTld(string $tld): bool
    {
        $vpnDomain = strtolower(app(VpnSettings::class)->domain());

        return $tld === self::PrivateSpace || $tld === $vpnDomain || str_ends_with($vpnDomain, '.'.$tld);
    }

    public static function assertAvailable(string $domain): void
    {
        if (in_array($domain, self::names(), true)) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_conflict',
                message: "Route domain [{$domain}] is reserved.",
                status: 409,
            );
        }
    }
}
