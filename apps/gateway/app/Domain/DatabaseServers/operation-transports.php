<?php

declare(strict_types=1);
use App\Infrastructure\DatabaseConnections\DatabaseSnapshotTransport;

return ['database_sqlite' => DatabaseSnapshotTransport::class];
