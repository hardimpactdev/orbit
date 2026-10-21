<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\NodeRole;

/** Runs before database:destroy can drop analytics storage. */
final readonly class AnalyticsDatabaseConnectionGuard implements DatabaseConnectionRemovalGuard
{
    public function __construct(private AnalyticsRoleSettingsRepository $settings) {}

    public function assertRemovable(DatabaseConnection $connection): void
    {
        if ($connection->database !== AnalyticsStorageConnection::POSTGRES_DATABASE || $connection->server === null) {
            return;
        }
        foreach (NodeRole::query()->where('role', RoleName::Analytics->value)->with('node')->get() as $role) {
            $settings = $this->settings->find($role->node);
            if ($settings?->postgresProcessId === $connection->server->process_id) {
                throw new ResourceOperationException('database.required_by_analytics', 'Move or remove the analytics role before destroying its database connection.', 409);
            }
        }
    }
}
