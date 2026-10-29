<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use Orbit\Sdk\GatewayApiException;

/** @internal Typed transport fields; dependency policy remains on the Gateway. */
final class DependencyResponseValue
{
    /** @param list<string> $keys
     * @return array<string, mixed> */
    public static function fields(#[\SensitiveParameter] mixed $v, array $keys): array
    {
        if (! is_array($v) || count($v) !== count($keys) || array_diff($keys, array_keys($v)) !== []) {
            self::invalid();
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $v[$key];
        }

        return $result;
    }

    public static function text(#[\SensitiveParameter] mixed $v): string
    {
        if (! is_string($v) || strlen($v) > 8_192 || ($length = preg_match_all('/./us', $v)) === false || $length > 2_048 || preg_match('/[\x00-\x1f\x7f]/', $v) === 1) {
            self::invalid();
        }

        return new CredentialRedactor()->redactText($v);
    }

    public static function integer(#[\SensitiveParameter] mixed $v): int
    {
        if (! is_int($v) || $v < 0) {
            self::invalid();
        }

        return $v;
    }

    public static function boolean(#[\SensitiveParameter] mixed $v): bool
    {
        if (! is_bool($v)) {
            self::invalid();
        }

        return $v;
    }

    /** @return list<mixed> */
    public static function rows(#[\SensitiveParameter] mixed $v, int $max): array
    {
        if (! is_array($v) || ! array_is_list($v) || count($v) > $max) {
            self::invalid();
        }

        return $v;
    }

    private static function invalid(): never
    {
        throw new GatewayApiException('Gateway response contains invalid dependency results.');
    }
}
