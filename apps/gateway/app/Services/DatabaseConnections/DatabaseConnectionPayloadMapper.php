<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use LogicException;

final readonly class DatabaseConnectionPayloadMapper
{
    public function __construct(
        private DatabaseConnectionTargetResolver $targets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(DatabaseConnection $connection, ?Node $caller = null): array
    {
        $connection->loadMissing(['node', 'targets.instance.app', 'targets.workspace.app']);

        /** @var list<array<string, string>> $targets */
        $targets = [];

        foreach ($connection->targets as $target) {
            if (
                $caller instanceof Node
                && $target->workspace instanceof Workspace
                && ! $this->targets->workspaceIsSupportedForCaller($target->workspace, $caller)
            ) {
                continue;
            }

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
        if ($target->instance instanceof Instance) {
            return [
                'type' => 'instance',
                'app' => $target->instance->app->name,
                'instance' => $target->instance->name,
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

        throw new LogicException('Database connection target must belong to an instance or workspace.');
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
