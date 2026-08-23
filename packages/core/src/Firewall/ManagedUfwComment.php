<?php

declare(strict_types=1);

namespace Orbit\Core\Firewall;

/**
 * Value-only managed UFW comment identity. Ownership is never inferred
 * from ports. A missing reason and name means there is no managed identity.
 */
final class ManagedUfwComment
{
    public static function from(?string $reason, ?string $name): ?string
    {
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        if (is_string($name) && $name !== '') {
            return "orbit:{$name}";
        }

        return null;
    }
}
