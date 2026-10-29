<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeFootprintResultCommand;
use App\Domain\Nodes\PrepareNodeAgentConfigurationCommand;
use App\Domain\Nodes\PrepareNodeFootprintCommand;

return [
    'node.prepare-footprint' => PrepareNodeFootprintCommand::class,
    'node.prepare-agent-configuration' => PrepareNodeAgentConfigurationCommand::class,
    'node.footprint-result' => NodeFootprintResultCommand::class,
];
