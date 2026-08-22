<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use Illuminate\Database\Eloquent\Builder;

/**
 * SQL eligibility for Ubuntu firewall targets. Twin of
 * FirewallRuleProbe::isUbuntuPlatform(): exact `ubuntu` or a literal
 * `ubuntu_` prefix. The LIKE escape keeps hyphenated values such as
 * `ubuntu-24-04` ineligible.
 */
final class FirewallTargetPlatform
{
    public static function constrainUbuntu(Builder $query): void
    {
        $query
            ->where('platform', 'ubuntu')
            ->orWhereRaw("platform like ? escape '!'", ['ubuntu!_%']);
    }
}
