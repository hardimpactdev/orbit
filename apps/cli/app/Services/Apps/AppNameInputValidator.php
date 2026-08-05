<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class AppNameInputValidator
{
    public function validate(string $name): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            return 'App name must match ^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$ (lowercase letters, digits, hyphens; no leading or trailing hyphen).';
        }

        if (strlen($name) > 40) {
            return 'App name must not exceed 40 characters.';
        }

        return null;
    }
}
