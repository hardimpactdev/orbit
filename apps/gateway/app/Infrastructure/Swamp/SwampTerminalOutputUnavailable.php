<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

/** A verified terminal root has a succeeded report step, but its receipt cannot be read. */
final class SwampTerminalOutputUnavailable extends ResourceOperationException
{
    /** @param array<string,mixed> $run */
    public function __construct(public readonly array $run, ?Throwable $previous = null)
    {
        parent::__construct('swamp.terminal_result_unavailable', 'The native terminal output is unavailable.', 503, $previous);
    }
}
