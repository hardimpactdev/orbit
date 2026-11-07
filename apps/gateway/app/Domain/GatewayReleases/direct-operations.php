<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseRunProjection;

return [
    'gateway.release' => [
        'workflow' => 'operation-gateway-release', 'workflow_inputs' => true,
        // A failed or paused release names the database copy from before its migration, and a failed migration its output.
        'terminal_result' => ['job' => 'release', 'step' => 'Hand over the release', 'native' => true,
            'captures' => ['snapshot' => ['job' => 'release', 'step' => 'Snapshot the database', 'field' => 'stdout'],
                'migrate' => ['job' => 'release', 'step' => 'Migrate', 'field' => 'stdout']]],
        'projection' => GatewayReleaseRunProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^gateway-release-[1-9][0-9]*$']],
    ],
];
