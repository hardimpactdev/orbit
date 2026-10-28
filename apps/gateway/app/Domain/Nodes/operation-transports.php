<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\BootstrapSshOperationTransport;
use App\Infrastructure\Nodes\ManagedAgentInstallOperationTransport;
use App\Infrastructure\Nodes\NodeWireGuardOperationTransport;

return ['node-wireguard' => NodeWireGuardOperationTransport::class, 'bootstrap-ssh' => BootstrapSshOperationTransport::class, 'node-agent' => ManagedAgentInstallOperationTransport::class];
