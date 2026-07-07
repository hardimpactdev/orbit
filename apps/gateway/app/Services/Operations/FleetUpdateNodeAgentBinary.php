<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;

final class FleetUpdateNodeAgentBinary
{
    public static function binPath(Node $node): string
    {
        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return NodeHostPaths::homeDirectoryFor($node->platform, $node->user).'/.local/bin/orbit-agent';
        }

        return '/usr/local/bin/orbit-agent';
    }
}
