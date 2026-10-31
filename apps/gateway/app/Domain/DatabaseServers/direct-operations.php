<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCleanupProjection;
use App\Domain\DatabaseServers\DatabaseCreateProjection;
use App\Domain\DatabaseServers\DatabaseServerCloseProjection;
use App\Domain\DatabaseServers\DatabaseUserProjection;

return ['database.mysql_clone' => [
    'workflow' => 'operation-database-mysql-clone',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'database_mysql_clone_writer',
    'projection' => DatabaseCreateProjection::class,
    'private_material' => ['password' => 'database.intent', 'payload' => 'database.intent'],
], 'database.create' => [
    'workflow' => 'operation-database-create',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'database_create_writer',
    'projection' => DatabaseCreateProjection::class,
    'private_material' => ['password' => 'database.intent', 'payload' => 'database.intent'],
], 'database_server.close' => [
    'workflow' => 'operation-database-server-close',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'database_server_close_writer',
    'projection' => DatabaseServerCloseProjection::class,
    'private_material' => ['password' => 'database.intent'],
], 'database.sqlite_cleanup' => [
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
