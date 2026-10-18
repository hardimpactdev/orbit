<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WireGuard\VpnSettings;
use Illuminate\Container\Attributes\Tag;

final readonly class ReservedPrivateHostname
{
    /** The private name space. Every reserved name lies under it, and no Cluster or Node TLD may cover it. */
    public const string PrivateSpace = 'orbit';

    public const string Gateway = 'gateway.'.self::PrivateSpace;

    public const string Metrics = 'metrics.'.self::PrivateSpace;

    /** @param iterable<ContributedPrivateHostnames> $contributions */
    public function __construct(
        #[Tag(ContributedPrivateHostnames::class)] private iterable $contributions,
    ) {}

    /** @return list<string> Core's names, then the names that extensions reserve. */
    public static function names(): array
    {
        return [self::Gateway, self::Metrics, WebSocketHostname::Value, AnalyticsHostname::Value, ...self::contributed()];
    }

    /** @return list<string> The names that extensions reserve, in provider order. */
    public static function contributed(): array
    {
        $names = [];

        foreach (app(self::class)->contributions as $contribution) {
            array_push($names, ...array_keys($contribution->hostnames()));
        }

        return $names;
    }

    /**
     * Whether a normalized Cluster or Node TLD overlaps either private zone. Private DNS answers every name under a
     * TLD, so equality, ancestors, and descendants of the private space or configured VPN domain are forbidden.
     * Existing configured domains may include an optional DNS root dot; compare their canonical names.
     */
    public static function isPrivateTld(string $tld): bool
    {
        $vpnDomain = strtolower(rtrim(app(VpnSettings::class)->domain(), '.'));

        return array_any([self::PrivateSpace, $vpnDomain], static fn (string $zone): bool => $tld === $zone || str_ends_with($tld, '.'.$zone) || str_ends_with($zone, '.'.$tld));
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
