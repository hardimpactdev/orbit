<?php

declare(strict_types=1);

use App\Domain\Nodes\GrantNodeGatewayAccessCommand;
use App\Domain\Nodes\NodeEnrollmentStateCommand;
use App\Domain\Nodes\NodeFootprintResultCommand;
use App\Domain\Nodes\NodeLifecycleCommand;
use App\Domain\Nodes\PrepareNodeAgentConfigurationCommand;
use App\Domain\Nodes\PrepareNodeFootprintCommand;
use App\Domain\Nodes\PrepareNodeWireGuardCommand;

return [
    'node.grant-gateway-access' => GrantNodeGatewayAccessCommand::class, 'node.prepare-wireguard' => PrepareNodeWireGuardCommand::class,
    'node.lifecycle-result' => NodeLifecycleCommand::class,
    'node.restore-enrollment-state' => NodeEnrollmentStateCommand::class,
    'node.begin-enrollment' => NodeEnrollmentStateCommand::class, 'node.activate-enrollment' => NodeEnrollmentStateCommand::class,
    'node.prepare-footprint' => PrepareNodeFootprintCommand::class,
    'node.prepare-agent-configuration' => PrepareNodeAgentConfigurationCommand::class,
    'node.footprint-result' => NodeFootprintResultCommand::class,
];
