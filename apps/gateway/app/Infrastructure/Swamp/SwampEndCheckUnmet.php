<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;

/**
 * A run that succeeded, while its history shows that the kind's selected end step did not succeed: skipped, failed or absent.
 * The run has ended, so no later read can change that. It keeps the code of an unavailable result, so a caller that does not
 * settle it, such as a child or a scheduled read, behaves as before.
 */
final class SwampEndCheckUnmet extends ResourceOperationException
{
    public function __construct(public readonly string $step, public readonly ?string $stepStatus)
    {
        parent::__construct('swamp.terminal_result_unavailable', 'The native terminal result is unavailable.', 503);
    }
}
