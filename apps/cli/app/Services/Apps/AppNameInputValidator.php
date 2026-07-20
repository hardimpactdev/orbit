<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class AppNameInputValidator
{
    public function validate(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return 'Project name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$ (lowercase letters, digits, hyphens; no leading or trailing hyphen).';
        }

        if (strlen($name) > 40) {
            return 'Project name must not exceed 40 characters.';
        }

        return null;
    }
}
