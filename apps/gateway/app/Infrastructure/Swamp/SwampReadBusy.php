<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use RuntimeException;

/** A view read that did not connect, because the serve window left only the part kept for Operations. Nothing reached serve. */
final class SwampReadBusy extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Swamp is busy with Operation starts; retry in '.$retryAfterSeconds.' s.');
    }
}
