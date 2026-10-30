<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Operation;

final readonly class DatabaseDestroySafety
{
    private const array SYSTEM_DATABASES = ['mysql', 'sys', 'performance_schema', 'information_schema', 'postgres'];

    /** @param list<string> $names
     * @param  list<string>  $prefixes
     */
    public function assertNames(array $names, array $prefixes = []): void
    {
        foreach ($names as $name) {
            $name = strtolower($name);
            if (in_array($name, self::SYSTEM_DATABASES, true) || str_starts_with($name, 'template')) {
                $this->refuseSystemDatabase();
            }
        }
        foreach ($prefixes as $prefix) {
            $prefix = strtolower($prefix);
            foreach ([...self::SYSTEM_DATABASES, 'template'] as $system) {
                if (str_starts_with($system, $prefix) || str_starts_with($prefix, 'template')) {
                    $this->refuseSystemDatabase();
                }
            }
        }
    }

    public function assertAcceptsCreates(DatabaseServer $server): void
    {
        if (Operation::query()->where('kind', 'database.destroy')->where('target_type', 'database_server')
            ->where('target_id', $server->id)
            ->whereNotIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])->exists()) {
            throw new ResourceOperationException('database.server_in_use', 'Database removal is unfinished on this server.', 409);
        }
    }

    private function refuseSystemDatabase(): never
    {
        throw new ResourceOperationException('database.system_database', 'System databases and prefixes cannot be removed.', 422);
    }
}
