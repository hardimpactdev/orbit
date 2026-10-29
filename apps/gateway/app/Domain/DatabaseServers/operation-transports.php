<?php

declare(strict_types=1);
use App\Infrastructure\DatabaseConnections\DatabaseSnapshotTransport;
use App\Infrastructure\DatabaseServers\DatabaseRemovalTransport;

return ['database_removal' => DatabaseRemovalTransport::class, 'database_sqlite' => DatabaseSnapshotTransport::class];
