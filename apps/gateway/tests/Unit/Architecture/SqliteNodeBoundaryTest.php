<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\DatabaseConnections\SqliteNodeDatabaseInspector;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;

arch('SQLite inspection uses named agent commands')
    ->expect(SqliteNodeDatabaseInspector::class)
    ->not->toUse([SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
