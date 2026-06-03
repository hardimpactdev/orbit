<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\Workspace;

final readonly class ToolRelatedProcessTarget
{
    public function __construct(
        public NodeTool $tool,
        public Node $node,
        public Process $process,
        public App $app,
        public ?Workspace $workspace,
    ) {}
}
