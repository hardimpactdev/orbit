<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final class SerializedArrays
{
    /** @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Serialized object keys must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, bool>
     */
    public static function boolMap(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_bool($item)) {
                throw new InvalidArgumentException('Serialized map values must be booleans.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, array{device: string, source: string, path: string}>
     */
    public static function mounts(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Serialized mount values must be objects.');
            }
            $mount = self::stringKeyed($item);
            if (
                array_keys($mount) !== ['device', 'source', 'path']
                || ! is_string($mount['device'])
                || ! is_string($mount['source'])
                || ! is_string($mount['path'])
            ) {
                throw new InvalidArgumentException('Serialized mount values are invalid.');
            }
            $result[$key] = [
                'device' => $mount['device'],
                'source' => $mount['source'],
                'path' => $mount['path'],
            ];
        }

        return $result;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, string>
     */
    public static function stringMap(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Serialized map values must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
