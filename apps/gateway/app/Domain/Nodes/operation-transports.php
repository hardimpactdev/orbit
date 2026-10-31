<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\ManagedAgentInstallOperationTransport;
use App\Infrastructure\Nodes\NodeWireGuardOperationTransport;

return ['node-wireguard' => NodeWireGuardOperationTransport::class, 'node-agent' => ManagedAgentInstallOperationTransport::class];
