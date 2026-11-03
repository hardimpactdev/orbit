<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;

/** Serve could not be read (connection, rate limit or no reply). The terminal projection retries; a mismatch never does. */
final class SwampTerminalReadUnavailable extends ResourceOperationException
{
    public function __construct()
    {
        parent::__construct('swamp.terminal_result_unavailable', 'The native terminal result is unavailable.', 503);
    }
}
