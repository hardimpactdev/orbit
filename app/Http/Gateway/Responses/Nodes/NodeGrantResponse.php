<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeGrantResponse
{
    public function __construct(
        public string $consumingNode,
        public string $servingNode,
        public bool $alreadyGranted,
    ) {}
}
