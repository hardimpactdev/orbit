<?php

declare(strict_types=1);

namespace App\Commands\App;

final class AppDevelopmentSetupStepPositionInput
{
    /**
     * @return array{values: array<string, int>}|array{error: array{field: string, message: string}}
     */
    public static function fromOptions(mixed $before, mixed $after): array
    {
        $beforeStep = self::positiveInteger($before);
        $afterStep = self::positiveInteger($after);

        if ($before !== null && $beforeStep === null) {
            return self::error('before', 'The --before option must be a positive integer.');
        }

        if ($after !== null && $afterStep === null) {
            return self::error('after', 'The --after option must be a positive integer.');
        }

        if ($beforeStep !== null && $afterStep !== null) {
            return self::error('before', 'Both insertion flags cannot be supplied.');
        }

        return ['values' => self::filled([
            'before' => $beforeStep,
            'after' => $afterStep,
        ])];
    }

    public static function positiveInteger(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  array<string, int|string|null>  $values
     * @return array<string, int|string>
     */
    public static function filled(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{error: array{field: string, message: string}}
     */
    public static function error(string $field, string $message): array
    {
        return ['error' => ['field' => $field, 'message' => $message]];
    }
}
