<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ubuntu firewall-target eligibility. Exact `ubuntu` or a literal
 * `ubuntu_` prefix. The LIKE escape keeps hyphenated values such as
 * `ubuntu-24-04` ineligible.
 */
final class FirewallTargetPlatform
{
    public static function isUbuntu(mixed $platform): bool
    {
        return is_string($platform) && ($platform === 'ubuntu' || str_starts_with($platform, 'ubuntu_'));
    }

    public static function constrainUbuntu(Builder $query): void
    {
        $query
            ->where('platform', 'ubuntu')
            ->orWhereRaw("platform like ? escape '!'", ['ubuntu!_%']);
    }
}
