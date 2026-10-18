<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Problems\ProblemCollection;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;

final class ObserveProblemNode extends ProblemCollectionJob
{
    /** @param list<int> $nodeIds */
    public function __construct(string $collection, array $nodeIds, public int $nodeId)
    {
        parent::__construct($collection, $nodeIds);
    }

    public function handle(RunDoctorAction $doctor): void
    {
        $node = Node::query()->find($this->nodeId);
        if ($node instanceof Node) {
            $context = $doctor->context($node);
            Cache::put(ProblemCollection::contextKey($this->collection, $this->nodeId), ['inspection' => $context->inspection->toArray(), 'failed' => $context->inspectionFailed], now()->addHour());
        }
    }
}
