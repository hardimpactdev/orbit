<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

final readonly class UnchangedNodeUpdate
{
    public function __construct(public Node $node) {}
}
