<?php

declare(strict_types=1);

namespace Orbit\Core\Nodes;

final class NodeTld
{
    public const string PRIVATE_SERVICE_NAMESPACE = 'orbit';

    public static function isValid(mixed $tld): bool
    {
        return (
            is_string($tld)
            && ! self::isReserved($tld)
            && strlen($tld) <= 63
            && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld) === 1
        );
    }

    public static function isReserved(string $tld): bool
    {
        return $tld === self::PRIVATE_SERVICE_NAMESPACE;
    }
}
