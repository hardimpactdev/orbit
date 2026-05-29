<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Models\Node;
use InvalidArgumentException;

class WebSocketBackendName
{
    public function forNode(Node $node): string
    {
        $name = trim($node->name);

        if ($name === '') {
            throw new InvalidArgumentException('The websocket backend name requires a node name.');
        }

        return "{$name}.websocket.orbit";
    }
}
