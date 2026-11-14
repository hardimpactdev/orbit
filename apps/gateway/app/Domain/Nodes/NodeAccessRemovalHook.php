<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

/**
 * Runs after `node:access:remove` deleted an edge, so an extension can end what the consumer reached only through it
 * ([Extensions](/reference/extensions#plug-in-points)). It runs while its extension is disabled too.
 */
interface NodeAccessRemovalHook
{
    public function removed(Node $consumer, Node $serving): void;
}
