<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseConnectionUpdateProjection;
use App\Domain\DatabaseServers\DatabaseCreateProjection;
use App\Domain\DatabaseServers\DatabaseDestroyProjection;
use App\Domain\DatabaseServers\DatabaseDestroyRun;
use App\Domain\DatabaseServers\DatabaseImportProjection;
use App\Domain\DatabaseServers\DatabaseReadProjection;
use App\Domain\DatabaseServers\DatabaseServerCloseProjection;
use App\Domain\DatabaseServers\DatabaseServerCreateProjection;
use App\Domain\DatabaseServers\DatabaseServerDestroyProjection;
use App\Domain\DatabaseServers\DatabaseUserProjection;
use App\Domain\DatabaseServers\DatabaseWriteProjection;

return ['database.update' => [
    'workflow' => 'operation-database-connection-update',
    'projection' => DatabaseConnectionUpdateProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'update', 'step' => 'Check the index fits', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-update-intent-[1-9][0-9]*$']],
], 'database.read' => [
    'workflow' => 'operation-database-read',
    'projection' => DatabaseReadProjection::class,
    'workflow_inputs' => true,
    // The native result cuts stdout short, so the client output comes as one capture.
    'terminal_result' => ['job' => 'read', 'step' => 'Run the read', 'alternatives' => ['Run the read on 209'], 'native' => true,
        'captures' => ['output' => ['job' => 'read', 'step' => 'Run the read', 'field' => 'stdout'],
            'local_output' => ['job' => 'read', 'step' => 'Run the read on 209', 'field' => 'stdout']]],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-read-intent-[1-9][0-9]*$']],
], 'database.create' => [
    'workflow' => 'operation-database-create',
    'projection' => DatabaseCreateProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'create', 'step' => 'Create owner and databases', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-create-intent-[1-9][0-9]*$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-create-owner-[1-9][0-9]*$']],
], 'database_server.destroy' => [
    'workflow' => 'operation-database-server-destroy',
    'projection' => DatabaseServerDestroyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'server', 'step' => 'Check it is gone', 'native' => true],
], 'database_server.create' => [
    'workflow' => 'operation-database-server-create',
    'projection' => DatabaseServerCreateProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'server', 'step' => 'Check the admin login', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-server-create-(intent|start|login)-[1-9][0-9]*$']],
], 'database.write' => [
    'workflow' => 'operation-database-write',
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-write-intent-[1-9][0-9]*$']],
    'projection' => DatabaseWriteProjection::class,
    'workflow_inputs' => true,
    // The native result cuts stdout short, so the client output comes as one capture.
    'terminal_result' => ['job' => 'write', 'step' => 'Run the statement', 'alternatives' => ['Run the statement on 209'], 'native' => true,
        'captures' => ['output' => ['job' => 'write', 'step' => 'Run the statement', 'field' => 'stdout'],
            'local_output' => ['job' => 'write', 'step' => 'Run the statement on 209', 'field' => 'stdout']]],
], 'database.import' => [
    'workflow' => 'operation-database-import',
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-import-intent-[1-9][0-9]*$']],
    'projection' => DatabaseImportProjection::class,
    'workflow_inputs' => true,
    // The script prints one result line and ends 0 for every outcome it names; the projection reads it.
    'terminal_result' => ['job' => 'import', 'step' => 'Load the dump', 'native' => true,
        'captures' => ['output' => ['job' => 'import', 'step' => 'Load the dump', 'field' => 'stdout']]],
], 'database_server.close' => [
    'workflow' => 'operation-database-server-close',
    'projection' => DatabaseServerCloseProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'closure', 'step' => 'Prove default is closed', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-server-close-intent-[1-9][0-9]*-(check|close)$']],
], 'database_user.create' => [
    'workflow' => 'operation-database-user-create',
    'projection' => DatabaseUserProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'user', 'step' => 'Apply login and grants', 'alternatives' => ['Check logical WAL'], 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-user-create-(wal|apply|check)-[1-9][0-9]*$']],
], 'database.destroy' => [
    'workflow' => 'operation-database-destroy',
    'projection' => DatabaseDestroyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'destroy', 'step' => 'Check they are gone', 'native' => true],
    'execution_copies' => [DatabaseDestroyRun::EXECUTION_COPY],
]];
