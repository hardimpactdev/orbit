<?php

declare(strict_types=1);

namespace Orbit\Core\Php;

use InvalidArgumentException;
use ValueError;

enum PhpCliVariant: string
{
    case Coverage = 'coverage';
    case Standard = 'standard';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $variant): string => $variant->value,
            self::cases(),
        );
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        return self::tryFrom($normalized);
    }

    public static function fromMixed(mixed $value): self
    {
        $variant = self::tryFromMixed($value);

        if ($variant instanceof self) {
            return $variant;
        }

        $rendered = is_scalar($value) ? (string) $value : get_debug_type($value);

        throw new InvalidArgumentException(
            "Invalid php-cli variant '{$rendered}'. Expected one of: ".implode(', ', self::values()).'.',
        );
    }

    public function includesPcov(): bool
    {
        return $this === self::Coverage;
    }

    public static function forRole(string $role): self
    {
        return match ($role) {
            'app-dev' => self::Coverage,
            'app-prod' => self::Standard,
            default => throw new ValueError("No php-cli variant baseline for role '{$role}'."),
        };
    }
}
