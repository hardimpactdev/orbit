<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use RuntimeException;

/** The Swamp Node has no token entrypoint yet: its swamp role was not converged since the renewal shipped. */
final class SwampNodeUnconvergedException extends RuntimeException
{
    public const string Code = 'swamp.gateway_token_entrypoint_missing';
}
