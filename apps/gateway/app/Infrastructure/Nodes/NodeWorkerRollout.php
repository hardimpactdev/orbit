<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Config;

/** Explicit operator rollout; task workers have their own lifecycle. */
final readonly class NodeWorkerRollout
{
    public function allows(Node $node): bool
    {
        return in_array($node->id, Config::array('swamp_workers.nodes'), true)
            && app(ManagedNodeEligibility::class)->allows($node)
            && TaskVm::query()->where('node_id', $node->id)->doesntExist();
    }
}
