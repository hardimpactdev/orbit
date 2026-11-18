<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\NodeAccess;

/**
 * Runs inside the IMMEDIATE transaction of `node:access:remove`, after it deleted the edge and before the commit, so an
 * extension commits its own record of the removal with the delete or not at all
 * ([Extensions](/reference/extensions#plug-in-points)). It runs while its extension is disabled too. Work outside the
 * database waits for `DB::afterCommit()`.
 */
interface NodeAccessRemovalTransactionHook
{
    public function removing(NodeAccess $edge): void;
}
