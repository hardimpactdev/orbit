<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Routes\ReservedPrivateHostname;

/**
 * The one reserved private hostname the Plausible dashboard answers on,
 * wherever the analytics role's node currently is.
 */
final readonly class AnalyticsHostname
{
    public const string Value = 'analytics.'.ReservedPrivateHostname::PrivateSpace;
}
