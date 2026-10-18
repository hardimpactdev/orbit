<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Models\DatabaseConnection;

/** Refuses removal before the database or its connection record changes. */
interface DatabaseConnectionRemovalGuard
{
    public function assertRemovable(DatabaseConnection $connection): void;
}
