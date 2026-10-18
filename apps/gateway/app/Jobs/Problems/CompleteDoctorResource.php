<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Domain\Problems\ProblemCollector;
use Illuminate\Support\Facades\Cache;

/** Failure accounting finishes before this batch starts, including worker timeouts. */
final class CompleteDoctorResource extends ProblemCollectionJob
{
    public function __construct(public CollectDoctorResource $inspection)
    {
        parent::__construct($inspection->collection, $inspection->nodeIds);
    }

    public function handle(ProblemCollector $collector): void
    {
        if (Cache::get($this->inspection->completionKey()) !== true) {
            $this->inspection->recordFailure($collector);
        }
        Cache::forget($this->inspection->completionKey());
    }
}
