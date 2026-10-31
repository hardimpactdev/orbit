<?php

declare(strict_types=1);

use App\Domain\Fleet\PeerDnsProjection;
use App\Domain\Fleet\RecipePrProjection;

return [
    'fleet.peer_dns' => [
        'workflow' => 'operation-fleet-peer-dns',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'peer_dns_writer',
        'projection' => PeerDnsProjection::class,
    ],
    'fleet.recipe_pr' => [
        'workflow' => 'operation-fleet-recipe-pr',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'recipe_pr_writer',
        'projection' => RecipePrProjection::class,
        'github_token' => ['repository' => 'hardimpactdev/orbit', 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'checks' => 'read']],
        'private_material' => ['github' => 'github.installation'],
    ],
];
