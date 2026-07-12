<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;

final readonly class ToolRuntimeTarget
{
    public function __construct(
        public NodeTool $tool,
        public Node $node,
        public ?Process $process,
    ) {}

    public function isToolOwned(): bool
    {
        return ! $this->process instanceof Process;
    }
}
