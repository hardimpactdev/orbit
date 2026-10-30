<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCleanupProjection;

return ['database.sqlite_cleanup' => [
    'workflow' => 'operation-database-cleanup',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'database_cleanup_writer',
    'projection' => DatabaseCleanupProjection::class,
]];
