<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppRegisterProjection;
use App\Domain\GitHub\GitHubAppSyncProjection;

return [
    'github.app_register' => [
        'workflow' => 'operation-github-app-register',
        'projection' => GitHubAppRegisterProjection::class,
        'private_material' => ['registration_code' => 'github.app.registration_code'],
        'secret_outputs' => ['app_credentials' => 'github.app.credentials'],
    ],
    'github.app_sync' => [
        'workflow' => 'operation-github-app-sync',
        'projection' => GitHubAppSyncProjection::class,
        'private_material' => ['app_token' => 'github.app.jwt'],
    ],
];
