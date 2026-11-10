<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** Serve could not be read (connection, rate limit, no reply, or a succeeded terminal step's output). The terminal projection retries; a mismatch never does. */
final class SwampTerminalReadUnavailable extends ResourceOperationException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('swamp.terminal_result_unavailable', 'The native terminal result is unavailable.', 503, $previous);
    }
}
