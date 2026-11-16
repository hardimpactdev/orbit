<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;

/** The CI places of a CI Node, its `settings.ci` (docs/reference/node-settings.md#ci-places). */
final class NodeCiData extends Data
{
    public function __construct(
        public int $places,
        public bool $proof_only = false,
    ) {}
}
