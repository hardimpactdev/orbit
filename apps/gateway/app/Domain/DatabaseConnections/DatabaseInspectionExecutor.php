<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Infrastructure\DatabaseConnections\RegisteredDatabaseInspectionExecutor;
use App\Models\DatabaseConnection;
use Illuminate\Container\Attributes\Bind;

#[Bind(RegisteredDatabaseInspectionExecutor::class)]
interface DatabaseInspectionExecutor
{
    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult;

    /** @return list<string> */
    public function tables(DatabaseConnection $connection): array;

    /** @return list<DatabaseSchemaTable> */
    public function schema(DatabaseConnection $connection): array;

    /** @return list<DatabaseTableColumn> */
    public function describe(DatabaseConnection $connection, string $table): array;
}
