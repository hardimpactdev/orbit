<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

use App\Domain\Routes\ReservedPrivateHostname;

final class ClusterTld
{
    public const string Pattern = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D';

    public static function normalize(string $tld): string
    {
        return mb_strtolower(trim($tld));
    }

    /** One lowercase DNS label that covers no private names ({@see ReservedPrivateHostname::isPrivateTld()}). */
    public static function isValid(string $tld): bool
    {
        $normalized = self::normalize($tld);

        return $normalized !== ''
            && preg_match(self::Pattern, $normalized) === 1
            && ! ReservedPrivateHostname::isPrivateTld($normalized);
    }
}
