<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Node;

/** Optional safe-input capture on the existing NodeFootprintArtifact contribution tag. */
interface NodeFootprintCapture extends NodeFootprintArtifact
{
    /**
     * Reads desired records and templates only, without host inspection or state changes.
     * Returns this artifact's public payload; never credentials or private rendered material.
     * Admission records any missing owned intent before calling this method.
     *
     * @return array<string, mixed>
     */
    public function capture(Node $node): array;
}
