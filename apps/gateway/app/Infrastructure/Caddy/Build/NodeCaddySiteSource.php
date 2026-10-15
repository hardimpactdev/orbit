<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Renders the sites one role or feature serves on a Node from committed database state only.
 *
 * Also a plug-in point: an extension contributes its own source, and the build renders it after core's sources
 * ([Extensions](/reference/extensions#plug-in-points)).
 */
interface NodeCaddySiteSource
{
    /** @return list<CaddySite> */
    public function sites(Node $node): array;
}
