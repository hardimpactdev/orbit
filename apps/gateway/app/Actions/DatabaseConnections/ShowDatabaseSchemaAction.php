<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Models\Operation;

final readonly class ShowDatabaseSchemaAction
{
    public function __construct(private ReadDatabaseConnectionAction $reads) {}

    public function execute(DatabaseConnection $connection): Operation
    {
        return $this->reads->execute($connection, 'schema');
    }
}
