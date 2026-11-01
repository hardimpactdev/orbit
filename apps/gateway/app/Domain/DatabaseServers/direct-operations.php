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
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'update', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-update-intent-[1-9][0-9]*$']],
], 'database.read' => [
    'workflow' => 'operation-database-read',
    'projection' => DatabaseReadProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'read', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-read-intent-[1-9][0-9]*$']],
], 'database.create' => [
    'workflow' => 'operation-database-create',
    'projection' => DatabaseCreateProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'create', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-create-intent-[1-9][0-9]*$']],
], 'database_server.destroy' => [
    'workflow' => 'operation-database-server-destroy',
    'projection' => DatabaseServerDestroyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
], 'database_server.create' => [
    'workflow' => 'operation-database-server-create',
    'projection' => DatabaseServerCreateProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'server', 'step' => 'result'],
], 'database.write' => [
    'workflow' => 'operation-database-write',
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$']],
    'projection' => DatabaseWriteProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'write', 'step' => 'result'],
], 'database_server.close' => [
    'workflow' => 'operation-database-server-close',
    'projection' => DatabaseServerCloseProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'closure', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-server-close-intent-[1-9][0-9]*$']],
], 'database.sqlite_cleanup' => [
    'workflow' => 'operation-database-cleanup',
    'projection' => DatabaseCleanupProjection::class,
], 'database_user.create' => [
    'workflow' => 'operation-database-user-create',
    'projection' => DatabaseUserProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'user', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-user-create-intent-[1-9][0-9]*$']],
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
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-clone-intent-[1-9][0-9]*$']],
], 'database.destroy' => [
    'workflow' => 'operation-database-destroy',
    'projection' => DatabaseDestroyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-destroy-intent-[1-9][0-9]*$']],
], 'database.destroy_owned' => [
    'workflow' => 'operation-database-destroy-owned',
    'projection' => DatabaseDestroyOwnedProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-destroy-owned-intent-[1-9][0-9]*-server-[1-9][0-9]*$']],
]];
