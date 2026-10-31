<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCleanupProjection;
use App\Domain\DatabaseServers\DatabaseUserProjection;

return ['database.sqlite_cleanup' => [
    'workflow' => 'operation-database-cleanup',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'database_cleanup_writer',
    'projection' => DatabaseCleanupProjection::class,
], 'database_user.create' => [
    'workflow' => 'operation-database-user-create',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'database_user_writer',
    'projection' => DatabaseUserProjection::class,
    'private_material' => ['password' => 'database.intent', 'sql' => 'database.intent'],
]];
