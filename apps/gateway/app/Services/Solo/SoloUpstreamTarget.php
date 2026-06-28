<?php

declare(strict_types=1);

namespace App\Services\Solo;

use App\Models\Node;

final readonly class SoloUpstreamTarget
{
    public function __construct(
        public Node $node,
        public string $url,
        public string $identity,
    ) {}
}
