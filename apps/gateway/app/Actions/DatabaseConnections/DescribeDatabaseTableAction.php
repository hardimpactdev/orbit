<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseTableName;
use App\Models\DatabaseConnection;
use App\Models\Operation;

final readonly class DescribeDatabaseTableAction
{
    public function __construct(private ReadDatabaseConnectionAction $reads) {}

    public function execute(DatabaseConnection $connection, string $table): Operation
    {
        return $this->reads->execute($connection, 'describe', '', DatabaseTableName::normalize($table));
    }
}
