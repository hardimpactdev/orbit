<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Renders the sites one role or feature serves on a Node from committed database state only. An extension contributes
 * a {@see ContributedCaddySiteSource}.
 */
interface NodeCaddySiteSource
{
    /** The stable render order, independent of provider discovery. */
    public function order(): int;

    /** @return list<CaddySite> */
    public function sites(Node $node): array;
}
