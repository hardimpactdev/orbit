<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ApplicationLogLines
{
    public const int Default = 100;

    /**
     * @throws GatewayApiException
     */
    public static function fromRequest(Request $request): int
    {
        $value = $request->input('lines', self::Default);

        if ($value === null) {
            return self::Default;
        }

        $parsed = self::parse($value);

        if ($parsed === null) {
            throw new GatewayApiException(
                'The lines value must be a positive integer.',
                'validation_failed',
                [
                    'field' => 'lines',
                    'value' => $value,
                ],
            );
        }

        return $parsed;
    }

    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (is_float($value) || ! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if ($text === '' || preg_match('/\A[1-9][0-9]*\z/', $text) !== 1) {
            return null;
        }

        $max = (string) PHP_INT_MAX;

        if (strlen($text) > strlen($max) || strlen($text) === strlen($max) && $text > $max) {
            return null;
        }

        return (int) $text;
    }
}
