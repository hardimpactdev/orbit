<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\NodeFacts\NodeAgentState;
use Spatie\LaravelData\Data;

/** The liveness of a Node's agent; `since` is the time of its last event while it is offline. */
final class NodeAgentData extends Data
{
    public function __construct(
        public NodeAgentState $state,
        public ?string $since,
    ) {}
}
