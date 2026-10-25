<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\DatabaseConnections\SqliteNodeDatabaseInspector;
use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\Instances\RemoteInstanceSqliteCloner;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;

arch('SQLite and checkout transfers use named agent commands')
    ->expect([SqliteNodeDatabaseInspector::class, ProtectedSqliteSnapshotTransfer::class, RemoteInstanceSqliteCloner::class, RemoteInstanceSqliteSeeder::class, RemoteInstanceTransferSource::class])
    ->not->toUse([SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
