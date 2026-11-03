<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetReleaseProjection;
use App\Domain\Fleet\PeerDnsProjection;
use App\Domain\Fleet\RecipePrProjection;

return [
    'fleet.rollout' => [
        'workflow' => 'operation-fleet-rollout',
        'projection' => FleetReleaseProjection::class,
    ],
    'fleet.cli_converge' => [
        'workflow' => 'operation-fleet-cli-converge',
        'projection' => FleetReleaseProjection::class,
    ],
    'fleet.peer_dns' => [
        'workflow' => 'operation-fleet-peer-dns',
        'projection' => PeerDnsProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'dns', 'step' => 'Check resolution', 'native' => true],
    ],
    'fleet.recipe_pr' => [
        'workflow' => 'operation-fleet-recipe-pr',
        'projection' => RecipePrProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'github', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^fleet-recipe-pr-[1-9][0-9]*$']],
    ],
];
