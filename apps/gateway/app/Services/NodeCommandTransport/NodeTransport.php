<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

enum NodeTransport: string
{
    case GatewayOnly = 'gateway-only';
    case AgentPush = 'agent-push';
}
