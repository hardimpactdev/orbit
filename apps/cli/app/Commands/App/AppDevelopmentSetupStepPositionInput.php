<?php

declare(strict_types=1);

namespace App\Commands\App;

final class AppDevelopmentSetupStepPositionInput
{
    /**
     * @return array{values: array{before?: int, after?: int}}|array{error: array{field: string, message: string}}
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

        $values = [];

        if ($beforeStep !== null) {
            $values['before'] = $beforeStep;
        }

        if ($afterStep !== null) {
            $values['after'] = $afterStep;
        }

        return ['values' => $values];
    }

    public static function positiveInteger(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return array{error: array{field: string, message: string}}
     */
    public static function error(string $field, string $message): array
    {
        return ['error' => ['field' => $field, 'message' => $message]];
    }
}
