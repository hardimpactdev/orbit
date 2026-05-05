<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeDefaultResponse
{
    public function __construct(
        public ?string $defaultNode,
    ) {}
}
