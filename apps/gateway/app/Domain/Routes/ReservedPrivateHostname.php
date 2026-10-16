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
     * The names that extensions reserve, in provider order. A name that core or another contribution already
     * reserves is a wiring error. It throws, so a contribution never takes or repeats a reserved name unseen.
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
                if (in_array($hostname, self::CoreNames, true) || in_array($hostname, $names, true)) {
                    throw new LogicException('The contribution ['.$contribution::class."] reserves the hostname [{$hostname}], which is already reserved.");
                }

                $names[] = $hostname;
            }
        }

        return $names;
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
