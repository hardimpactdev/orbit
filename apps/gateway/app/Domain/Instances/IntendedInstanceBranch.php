<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

final class IntendedInstanceBranch
{
    public static function forInstance(Instance $instance, ?string $defaultBranch): ?string
    {
        return $instance->branch ?? self::resolve($instance->name, $instance->branch_override, $defaultBranch);
    }

    public static function resolve(string $name, ?string $override, ?string $defaultBranch): ?string
    {
        return $override ?? ($name === DefaultInstanceName::Name ? $defaultBranch : $name);
    }
}
