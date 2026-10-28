<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationKinds;
use ReflectionClass;

/** Ask the kit owner, including when an earlier foundation has no capability API yet. */
final class FleetKitCapabilities
{
    public static function visitsSupported(): bool
    {
        return self::supported(['children', 'list_of', 'receipts']);
    }

    public static function cliSupported(): bool
    {
        return self::supported(['children', 'receipts']);
    }

    /** @param list<string> $capabilities */
    private static function supported(array $capabilities): bool
    {
        $loader = new ReflectionClass(OperationKinds::class);
        if (! $loader->hasMethod('supports')) {
            return false;
        }
        $supports = $loader->getMethod('supports');

        return $supports->isPublic() && $supports->isStatic()
            && array_all($capabilities, static fn (string $capability): bool => $supports->invoke(null, $capability) === true);
    }
}
