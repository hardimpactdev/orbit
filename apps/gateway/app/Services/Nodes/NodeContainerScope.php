<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;

final readonly class NodeContainerScope
{
    public static function forNode(Node $node): string
    {
        $host = trim($node->host);

        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return $host;
        }

        return $node->name;
    }
}
