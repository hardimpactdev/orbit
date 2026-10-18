<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Domain\Problems\ProblemCollection;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemFiler;
use Illuminate\Support\Facades\Cache;

final class CollectProblemSignals extends ProblemCollectionJob
{
    public function handle(ProblemCollector $collector, ProblemFiler $filer): void
    {
        foreach ([...$collector->collect(), ...$filer->file()] as $error) {
            report($error);
        }
        foreach ($this->nodeIds as $id) {
            Cache::forget(ProblemCollection::contextKey($this->collection, $id));
        }
    }
}
