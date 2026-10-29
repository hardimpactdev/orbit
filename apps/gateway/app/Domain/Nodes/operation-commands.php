<?php

declare(strict_types=1);

use App\Domain\Nodes\GrantNodeGatewayAccessCommand;
use App\Domain\Nodes\NodeFootprintResultCommand;
use App\Domain\Nodes\NodeLifecycleCommand;
use App\Domain\Nodes\NodeNetworkResultCommand;
use App\Domain\Nodes\NodeRoleResultCommand;
use App\Domain\Nodes\PrepareNodeAgentConfigurationCommand;
use App\Domain\Nodes\PrepareNodeFootprintCommand;
use App\Domain\Nodes\PrepareNodeNetworkCommand;
use App\Domain\Nodes\PrepareNodePhpPackagesCommand;
use App\Domain\Nodes\PrepareNodeRoleCommand;
use App\Domain\Nodes\PrepareNodeWireGuardCommand;
use App\Domain\Nodes\RemoveNodeAccessEdgeCommand;
use App\Domain\Nodes\TransferNodeRoleCommand;

return ['node.begin-removal' => NodeLifecycleCommand::class, 'node.restore-removal-state' => NodeLifecycleCommand::class, 'node.lifecycle-result' => NodeLifecycleCommand::class, 'node.prepare-network' => PrepareNodeNetworkCommand::class, 'node.network-result' => NodeNetworkResultCommand::class, 'node.transfer-role' => TransferNodeRoleCommand::class, 'node.prepare-role' => PrepareNodeRoleCommand::class, 'node.role-result' => NodeRoleResultCommand::class,
    'node.grant-gateway-access' => GrantNodeGatewayAccessCommand::class, 'node.prepare-wireguard' => PrepareNodeWireGuardCommand::class,
    'node.remove-access-edge' => RemoveNodeAccessEdgeCommand::class,
    'node.prepare-php-packages' => PrepareNodePhpPackagesCommand::class,
    'node.prepare-footprint' => PrepareNodeFootprintCommand::class,
    'node.footprint-result' => NodeFootprintResultCommand::class,
    'node.prepare-agent-configuration' => PrepareNodeAgentConfigurationCommand::class];
