<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetReleaseProjection;
use App\Domain\Fleet\PeerDnsProjection;
use App\Domain\Fleet\RecipeDirectProjection;
use App\Domain\Fleet\RecipePrProjection;

return [
    'fleet.recipe' => [
        'workflow' => 'operation-fleet-recipe-direct',
        'projection' => RecipeDirectProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'result', 'step' => 'result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^recipe-operation-[1-9][0-9]*-observer$']],
    ],
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
        'terminal_result' => ['job' => 'dns', 'step' => 'result'],
    ],
    'fleet.recipe_pr' => [
        'workflow' => 'operation-fleet-recipe-pr',
        'projection' => RecipePrProjection::class,
        'github_token' => ['repository' => 'hardimpactdev/orbit', 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'checks' => 'read']],
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'github', 'step' => 'result'],
    ],
];
