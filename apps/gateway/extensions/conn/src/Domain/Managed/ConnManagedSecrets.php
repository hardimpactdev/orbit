<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use SensitiveParameter;

/** Makes and digests the two managed secrets ([Credentials](/reference/conn#credentials)). Only digests are stored. */
final class ConnManagedSecrets
{
    public const string ServicePrefix = 'conn_svc_';

    public const string TokenPrefix = 'conn_mt_';

    public static function service(): string
    {
        return self::ServicePrefix.self::random();
    }

    public static function token(): string
    {
        return self::TokenPrefix.self::random();
    }

    public static function digest(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** The current Gateway time with microseconds, the form of every managed timestamp. */
    public static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.up');
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
