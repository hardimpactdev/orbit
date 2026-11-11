<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final class IntendedInstanceBranch
{
    public static function resolve(string $name, ?string $override, ?string $defaultBranch): ?string
    {
        return $override ?? ($name === DefaultInstanceName::Name ? $defaultBranch : $name);
    }
}
