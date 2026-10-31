<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCleanupProjection;
use App\Domain\DatabaseServers\DatabaseCloneProjection;
use App\Domain\DatabaseServers\DatabaseConnectionUpdateProjection;
use App\Domain\DatabaseServers\DatabaseCreateProjection;
use App\Domain\DatabaseServers\DatabaseDestroyOwnedProjection;
use App\Domain\DatabaseServers\DatabaseDestroyProjection;
use App\Domain\DatabaseServers\DatabaseReadProjection;
use App\Domain\DatabaseServers\DatabaseServerCloseProjection;
use App\Domain\DatabaseServers\DatabaseServerCreateProjection;
use App\Domain\DatabaseServers\DatabaseServerDestroyProjection;
use App\Domain\DatabaseServers\DatabaseSqliteProjection;
use App\Domain\DatabaseServers\DatabaseUserProjection;
use App\Domain\DatabaseServers\DatabaseWriteProjection;

return ['database.update' => [
    'workflow' => 'operation-database-connection-update',
    'projection' => DatabaseConnectionUpdateProjection::class,
    'private_material' => ['password' => 'database.intent'],
], 'database.read' => [
    'workflow' => 'operation-database-read',
    'projection' => DatabaseReadProjection::class,
    'private_material' => array_fill_keys(['password', 'sql', 'dsn', 'username'], 'database.intent'),
], 'database.create' => [
    'workflow' => 'operation-database-create',
    'projection' => DatabaseCreateProjection::class,
    'private_material' => ['password' => 'database.intent', 'payload' => 'database.intent'],
], 'database_server.destroy' => [
    'workflow' => 'operation-database-server-destroy',
    'projection' => DatabaseServerDestroyProjection::class,
], 'database_server.create' => [
    'workflow' => 'operation-database-server-create',
    'projection' => DatabaseServerCreateProjection::class,
    'private_material' => ['password' => 'database.intent', 'environment' => 'database.intent', 'acl' => 'database.intent', 'payload' => 'database.intent'],
], 'database.write' => [
    'workflow' => 'operation-database-write',
    'projection' => DatabaseWriteProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'write', 'step' => 'result'],
], 'database_server.close' => [
    'workflow' => 'operation-database-server-close',
    'projection' => DatabaseServerCloseProjection::class,
    'private_material' => ['password' => 'database.intent'],
], 'database.sqlite_cleanup' => [
    'workflow' => 'operation-database-cleanup',
    'projection' => DatabaseCleanupProjection::class,
], 'database_user.create' => [
    'workflow' => 'operation-database-user-create',
    'projection' => DatabaseUserProjection::class,
    'private_material' => ['password' => 'database.intent', 'sql' => 'database.intent'],
], 'database.sqlite_export' => [
    'workflow' => 'operation-database-sqlite-export',
    'projection' => DatabaseSqliteProjection::class,
], 'database.sqlite_seed' => [
    'workflow' => 'operation-database-sqlite-seed',
    'projection' => DatabaseSqliteProjection::class,
], 'database.sqlite_seed_abandon' => [
    'workflow' => 'operation-database-sqlite-seed-abandon',
    'projection' => DatabaseSqliteProjection::class,
], 'database.clone' => [
    'workflow' => 'operation-database-clone',
    'projection' => DatabaseCloneProjection::class,
    'private_material' => ['password' => 'database.intent', 'payload' => 'database.intent'],
], 'database.destroy' => [
    'workflow' => 'operation-database-destroy',
    'projection' => DatabaseDestroyProjection::class,
    'private_material' => ['password' => 'database.intent'],
], 'database.destroy_owned' => [
    'workflow' => 'operation-database-destroy-owned',
    'projection' => DatabaseDestroyOwnedProjection::class,
    'private_material' => ['payload' => 'database.intent'],
]];
