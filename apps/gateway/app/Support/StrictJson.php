<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonException;

/** Strict JSON transport: preserve object/list types and reject duplicate keys at every depth. */
final class StrictJson
{
    public static function decode(string $bytes, int $limit, int $depth = 24): mixed
    {
        if ($depth < 1 || $depth > PHP_INT_MAX - 2) {
            throw new InvalidArgumentException('Invalid JSON depth limit.');
        }
        if (strlen($bytes) > $limit) {
            throw new InvalidArgumentException('JSON exceeds its byte limit.');
        }
        try {
            $value = json_decode($bytes, false, $depth + 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid complete UTF-8 JSON.', previous: $exception);
        }
        $stack = [];
        $expectsKey = [];
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $c = $bytes[$i];
            if ($c === '{' || $c === '[') {
                $stack[] = $c === '{' ? [] : null;
                $expectsKey[] = $c === '{';
                if (count($stack) > $depth) {
                    throw new InvalidArgumentException('JSON exceeds its depth limit.');
                }
            } elseif ($c === '}' || $c === ']') {
                array_pop($stack);
                array_pop($expectsKey);
            } elseif ($c === ',') {
                $expectsKey[count($stack) - 1] = true;
            } elseif ($c === '"') {
                $start = $i++;
                while ($bytes[$i] !== '"') {
                    $i += $bytes[$i] === '\\' ? 2 : 1;
                }
                $level = count($stack) - 1;
                if ($level >= 0 && $stack[$level] !== null && $expectsKey[$level]) {
                    $key = json_decode(substr($bytes, $start, $i - $start + 1), flags: JSON_THROW_ON_ERROR);
                    if (! is_string($key)) {
                        throw new InvalidArgumentException('Expected a JSON object key.');
                    }
                    if (array_key_exists($key, $stack[$level])) {
                        throw new InvalidArgumentException('JSON contains a duplicate object key.');
                    }
                    $stack[$level][$key] = true;
                    $expectsKey[$level] = false;
                }
            }
        }

        return $value;
    }

    /** @param list<string> $keys
     * @phpstan-assert \stdClass $value
     *
     * @return array<string, mixed>
     */
    public static function fields(mixed $value, array $keys): array
    {
        if (! $value instanceof \stdClass) {
            throw new InvalidArgumentException('Expected a JSON object.');
        }
        $fields = get_object_vars($value);
        $actual = array_keys($fields);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('JSON object fields differ from the contract.');
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    public static function associative(\stdClass $value): array
    {
        return array_map(self::associativeValue(...), get_object_vars($value));
    }

    private static function associativeValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::associative($value);
        }

        return is_array($value) ? array_map(self::associativeValue(...), $value) : $value;
    }
}
