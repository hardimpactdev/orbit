<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;

/**
 * A run that succeeded, while its history shows that the kind's selected end step was skipped or failed. The run has ended, so no
 * later read can change that. An absent or unfinished step is not final: serve may not have written it yet, and the next pass reads
 * again. It keeps the code of an unavailable result, so a caller that does not settle it, such as a child or a scheduled read,
 * behaves as before.
 */
final class SwampEndCheckUnmet extends ResourceOperationException
{
    /** The end step statuses that a succeeded run keeps for good. */
    public const array Final = ['skipped', 'failed'];

    public function __construct(public readonly string $step, public readonly string $stepStatus)
    {
        parent::__construct('swamp.terminal_result_unavailable', 'The native terminal result is unavailable.', 503);
    }
}
