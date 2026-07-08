<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use InvalidArgumentException;

final readonly class LocalWorkspaceSetupStepTimeout
{
    public static function from(mixed $value): int
    {
        if (is_int($value) && $value >= 1 && $value <= 3600) {
            return $value;
        }

        throw new InvalidArgumentException('Workspace setup timeout is invalid.');
    }
}
