<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Services\Support\GatewayActionResult;

final readonly class NodeBootstrapCompletionResult
{
    public function __construct(
        public GatewayActionResult $result,
        public bool $completedNow,
    ) {}
}
