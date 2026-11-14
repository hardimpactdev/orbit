<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

/**
 * Runs in the last transaction of a Node removal, just before the Node row is deleted, so an extension can keep what must outlive
 * the Node ([Extensions](/reference/extensions#plug-in-points)). It runs while its extension is disabled too.
 */
interface NodeRemovalHook
{
    public function removing(Node $node): void;
}
