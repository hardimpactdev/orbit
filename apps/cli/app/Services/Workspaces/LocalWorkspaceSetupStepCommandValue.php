<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use InvalidArgumentException;

final readonly class LocalWorkspaceSetupStepCommandValue
{
    public static function from(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '' && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new InvalidArgumentException('Workspace setup command is invalid.');
    }
}
