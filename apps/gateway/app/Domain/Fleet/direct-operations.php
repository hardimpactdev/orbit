<?php

declare(strict_types=1);

use App\Domain\Fleet\DockerMigrationProjection;
use App\Domain\Fleet\PeerDnsProjection;
use App\Domain\Fleet\RecipeDirectProjection;
use App\Domain\Fleet\RecipePrProjection;

return [
    'fleet.recipe' => [
        'workflow' => 'operation-fleet-recipe-direct',
        'projection' => RecipeDirectProjection::class,
        'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials'],
    ],
    'fleet.docker_migrate' => [
        'workflow' => 'operation-fleet-docker-migrate',
        'projection' => DockerMigrationProjection::class,
        'private_material' => ['processes' => 'fleet.docker_processes'],
    ],
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
