<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloHumanValueFormatter
{
    public function key(string $key): string
    {
        return ucfirst(str_replace(search: '_', replace: ' ', subject: $key));
    }

    public function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
