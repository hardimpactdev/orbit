<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeFootprintResultCommand;
use App\Domain\Nodes\PrepareNodeAgentConfigurationCommand;
use App\Domain\Nodes\PrepareNodeFootprintCommand;
use App\Domain\Nodes\PrepareNodePhpPackagesCommand;
use App\Domain\Nodes\PrepareNodeWireGuardCommand;
use App\Domain\Nodes\RemoveNodeAccessEdgeCommand;

return ['node.prepare-wireguard' => PrepareNodeWireGuardCommand::class,
    'node.remove-access-edge' => RemoveNodeAccessEdgeCommand::class,
    'node.prepare-php-packages' => PrepareNodePhpPackagesCommand::class,
    'node.prepare-footprint' => PrepareNodeFootprintCommand::class,
    'node.footprint-result' => NodeFootprintResultCommand::class,
    'node.prepare-agent-configuration' => PrepareNodeAgentConfigurationCommand::class];
