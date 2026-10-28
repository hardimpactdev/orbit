<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Managed removal starts an Operation; registry-only removal returns the removed record. */
final readonly class DestroyedDatabaseConnectionResponse
{
    public function __construct(public DatabaseConnectionResponse|OperationResponse $result) {}
}
