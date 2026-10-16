<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\ReservedPrivateHostname;

final class NodeTld
{
    public const string Pattern = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D';

    public static function normalize(string $tld): string
    {
        $normalized = trim($tld);

        if (str_starts_with($normalized, '.')) {
            $normalized = substr(string: $normalized, offset: 1);
        }

        return strtolower($normalized);
    }

    /** Lowercase DNS labels, at most 253 characters, that cover no private names ({@see ReservedPrivateHostname::isPrivateTld()}). */
    public static function isValid(string $tld): bool
    {
        $normalized = self::normalize($tld);

        return $normalized !== ''
            && strlen($normalized) <= 253
            && preg_match(self::Pattern, $normalized) === 1
            && ! ReservedPrivateHostname::isPrivateTld($normalized);
    }
}
