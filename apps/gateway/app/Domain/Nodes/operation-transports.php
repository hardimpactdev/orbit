<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\BootstrapSshOperationTransport;
use App\Infrastructure\Nodes\ManagedAgentInstallOperationTransport;

return ['node-agent' => ManagedAgentInstallOperationTransport::class, 'bootstrap-ssh' => BootstrapSshOperationTransport::class];
