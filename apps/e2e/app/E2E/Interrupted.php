<?php

declare(strict_types=1);

namespace App\E2E;

use Error;

/**
 * A signal stopped the command. It is an Error, not an Exception, so retry loops that tolerate a failed Incus
 * call let it through, and the work unwinds and removes what it created.
 */
final class Interrupted extends Error
{
    public static function bySignal(int $signal): self
    {
        return new self("Interrupted by signal {$signal}.");
    }
}
