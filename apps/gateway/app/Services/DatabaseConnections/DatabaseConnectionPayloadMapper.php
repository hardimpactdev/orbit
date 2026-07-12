<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Workspace;
use LogicException;

final class DatabaseConnectionPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(DatabaseConnection $connection): array
    {
        $connection->loadMissing(['node', 'targets.appInstance.app', 'targets.workspace.app']);

        /** @var list<array<string, string>> $targets */
        $targets = [];

        foreach ($connection->targets as $target) {
            $targets[] = $this->targetPayload($target);
        }

        usort($targets, $this->compareTargets(...));

        return [
            'slug' => $connection->slug,
            'driver' => $connection->driver,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'path' => $connection->path,
            'username' => $connection->username,
            'node' => $connection->node?->name,
            'targets' => $targets,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function targetPayload(DatabaseConnectionTarget $target): array
    {
        if ($target->appInstance instanceof AppInstance) {
            return [
                'type' => 'app_instance',
                'app' => $target->appInstance->app->name,
                'instance' => $target->appInstance->name,
                'env_prefix' => $target->env_prefix,
            ];
        }

        if ($target->workspace instanceof Workspace) {
            return [
                'type' => 'workspace',
                'name' => $target->workspace->name,
                'env_prefix' => $target->env_prefix,
            ];
        }

        throw new LogicException('Database connection target must belong to an app instance or workspace.');
    }

    /**
     * @param  array<string, string>  $first
     * @param  array<string, string>  $second
     */
    private function compareTargets(array $first, array $second): int
    {
        return $this->targetSortKey($first) <=> $this->targetSortKey($second);
    }

    /** @param array<string, string> $target */
    private function targetSortKey(array $target): string
    {
        return implode(':', [
            $target['type'],
            $target['name'] ?? $target['app'] ?? '',
            $target['instance'] ?? '',
            $target['env_prefix'],
        ]);
    }
}
