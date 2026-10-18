<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use Illuminate\Bus\ChainedBatch;

/** Even dispatching the next batch must yield promptly to Horizon termination. */
final class DispatchProblemBatch extends ChainedBatch
{
    public int $timeout = ProblemCollectionJob::Timeout;

    public int $tries = 1;

    public bool $failOnTimeout = true;
}
