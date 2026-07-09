<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;

final class FleetUpdateNodeAgentBinary
{
    public static function binPath(Node $node): string
    {
        return NodeHostPaths::homeDirectoryFor($node->platform, $node->user).'/.local/bin/orbit-agent';
    }
}
