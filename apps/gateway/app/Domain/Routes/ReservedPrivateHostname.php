<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Extensions\Contributions;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketHostname;
use LogicException;

final class ReservedPrivateHostname
{
    /** @var list<string> */
    public const array CoreNames = ['gateway.orbit', 'metrics.orbit', WebSocketHostname::Value, AnalyticsHostname::Value];

    /** A lowercase DNS name in the reserved private space: one or more labels, then `orbit`. */
    private const string OrbitName = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+orbit\z/';

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
            return 'is not a DNS name under .orbit';
        }

        if (in_array($hostname, self::CoreNames, true) || in_array($hostname, $contributed, true)) {
            return 'is already reserved';
        }

        return null;
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
