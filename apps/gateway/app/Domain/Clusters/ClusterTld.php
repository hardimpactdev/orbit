<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

use App\Domain\WireGuard\VpnSettings;

final class ClusterTld
{
    private const string PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D';

    public static function normalize(string $tld): string
    {
        return mb_strtolower(trim($tld));
    }

    /** One lowercase DNS label that is not the private VPN domain ({@see VpnSettings::isDomain()}). */
    public static function isValid(string $tld): bool
    {
        $normalized = self::normalize($tld);

        return $normalized !== ''
            && preg_match(self::PATTERN, $normalized) === 1
            && ! app(VpnSettings::class)->isDomain($normalized);
    }
}
