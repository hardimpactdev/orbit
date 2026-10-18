<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Models\DatabaseConnection;

/** Validates before the write and reconverges after the outer transaction commits. */
interface DatabaseConnectionUpdateHook
{
    public function validate(DatabaseConnection $proposed): void;

    public function afterCommit(DatabaseConnection $connection): void;
}
