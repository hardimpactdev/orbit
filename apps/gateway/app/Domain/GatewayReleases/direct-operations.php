<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseRunProjection;
use App\Domain\GatewayReleases\GatewaySwampLoadProjection;

return [
    'gateway.release' => [
        'workflow' => 'operation-gateway-release', 'workflow_inputs' => true,
        // A failed or paused release names the database copy from before its migration.
        'terminal_result' => ['job' => 'release', 'step' => 'Hand over the release', 'native' => true,
            'captures' => ['snapshot' => ['job' => 'release', 'step' => 'Snapshot the database', 'field' => 'stdout']]],
        'projection' => GatewayReleaseRunProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^gateway-release-[1-9][0-9]*$']],
    ],
    'gateway.swamp_load' => [
        'workflow' => 'operation-gateway-swamp-load', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'load', 'step' => 'load'], 'projection' => GatewaySwampLoadProjection::class,
    ],
];
