<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Models\Node;

/** The modification times of files on a Node, in one read for many paths. */
interface NodeFileTimes
{
    /**
     * @param  list<string>  $paths
     * @return array<string, int|null>|null Each path's time, null for a missing path; null as a whole when the Node did not answer.
     */
    public function read(Node $node, array $paths): ?array;
}
