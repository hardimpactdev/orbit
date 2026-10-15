<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/** A manager whose packages a Node role can own. A Tool never installs, updates, adopts, or removes those. */
interface ReservesRolePackages
{
    public const string ERROR_CODE = 'tool.package_role_owned';

    public function roleOwns(string $package): bool;
}
