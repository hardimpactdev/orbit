<?php

declare(strict_types=1);

namespace App\Commands\Activity;

final class ActivityGatewayResponseArrays
{
    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $result = [];

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $value[$key];
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<array<string, mixed>>
     */
    public static function listOfStringKeyed(array $value): array
    {
        $result = [];

        foreach (array_keys($value) as $index) {
            if (! is_array($value[$index])) {
                continue;
            }

            $result[] = self::stringKeyed($value[$index]);
        }

        return $result;
    }
}
