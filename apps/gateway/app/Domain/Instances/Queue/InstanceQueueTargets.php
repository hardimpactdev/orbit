<?php

declare(strict_types=1);

namespace App\Domain\Instances\Queue;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Support\Str;

final readonly class InstanceQueueTargets
{
    /** The queue store of the Instance, or null when its environment and connections name none. */
    public function for(Instance $instance): ?InstanceQueueTarget
    {
        $environment = [];
        foreach ($instance->environmentValues()->get(['env_key', 'env_value']) as $value) {
            $environment[$value->env_key] = $value->env_value;
        }
        $database = $this->connection($instance, 'DB', [DatabaseDriver::Mysql, DatabaseDriver::Pgsql]);

        return match ($environment['QUEUE_CONNECTION'] ?? null) {
            'redis' => ($store = $this->connection($instance, 'REDIS', [DatabaseDriver::Redis])) === null ? null : new InstanceQueueTarget(
                'redis',
                $store,
                $database,
                $environment['REDIS_PREFIX'] ?? Str::slug($environment['APP_NAME'] ?? 'laravel', '_').'_database_',
                [($environment['REDIS_QUEUE'] ?? '') !== '' ? $environment['REDIS_QUEUE'] : 'default'],
            ),
            'database' => $database === null ? null : new InstanceQueueTarget('database', $database, $database, '', []),
            default => null,
        };
    }

    /** @param list<DatabaseDriver> $drivers */
    private function connection(Instance $instance, string $prefix, array $drivers): ?DatabaseConnection
    {
        return $instance->databaseConnectionTargets()->with('databaseConnection')->get()
            ->first(static fn (DatabaseConnectionTarget $target): bool => $target->prefix === $prefix
                && in_array($target->databaseConnection->driver, $drivers, true))
            ?->databaseConnection;
    }
}
