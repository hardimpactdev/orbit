<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NodeAgentSshExecutor::class)]
interface NodeAgentRuntime
{
    public function converge(Node $node): void;

    public function remove(Node $node): void;
}
