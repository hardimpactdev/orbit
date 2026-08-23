<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ubuntu firewall-target eligibility. Exact lowercase `ubuntu` or a
 * literal lowercase `ubuntu_` prefix. Any suffix text after that prefix
 * is allowed. SQLite GLOB is case-sensitive and treats `_` as a literal,
 * so hyphenated values such as `ubuntu-24-04` stay ineligible.
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
            ->orWhereRaw('platform glob ?', ['ubuntu_*']);
    }
}
