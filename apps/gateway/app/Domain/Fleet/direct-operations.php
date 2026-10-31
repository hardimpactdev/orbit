<?php

declare(strict_types=1);

use App\Domain\Fleet\PeerDnsProjection;
use App\Domain\Fleet\RecipePrProjection;

return [
    'fleet.peer_dns' => [
        'workflow' => 'operation-fleet-peer-dns',
        'projection' => PeerDnsProjection::class,
    ],
    'fleet.recipe_pr' => [
        'workflow' => 'operation-fleet-recipe-pr',
        'projection' => RecipePrProjection::class,
        'github_token' => ['repository' => 'hardimpactdev/orbit', 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'checks' => 'read']],
        'private_material' => ['github' => 'github.installation'],
    ],
];
