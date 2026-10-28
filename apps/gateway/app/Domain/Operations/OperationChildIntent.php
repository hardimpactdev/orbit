<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** Canonical, type-preserving identity for server-recorded child intent. */
final class OperationChildIntent
{
    /** @param array<array-key,mixed> $value */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(self::ordered($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array<array-key,mixed> $left
     * @param  array<array-key,mixed>  $right
     */
    public static function same(array $left, array $right): bool
    {
        return self::ordered($left) === self::ordered($right);
    }

    /** @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private static function ordered(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ordered($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
