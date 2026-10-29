<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final class MachineArchitecture
{
    /** The one architecture a Linux Node may have. Orbit does not support Linux on ARM. */
    public const string Linux = 'x86_64';

    private const string PATTERN = '/\A[A-Za-z0-9_.-]{1,64}\z/D';

    public static function isValid(string $architecture): bool
    {
        return preg_match(self::PATTERN, $architecture) === 1;
    }
}
