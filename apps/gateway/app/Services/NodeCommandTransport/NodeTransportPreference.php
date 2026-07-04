<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

enum NodeTransportPreference: string
{
    case AgentPush = 'agent-push';
    case TransitionalSshFallback = 'transitional-ssh-fallback';
    case Auto = 'auto';
}
