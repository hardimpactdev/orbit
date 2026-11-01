<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewaySwampLoadProjection;

return [
    'gateway.swamp_load' => [
        'workflow' => 'operation-gateway-swamp-load', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'load', 'step' => 'load'], 'projection' => GatewaySwampLoadProjection::class,
    ],
];
