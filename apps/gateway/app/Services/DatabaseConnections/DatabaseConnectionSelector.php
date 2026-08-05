<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Workspace;

final readonly class DatabaseConnectionSelector
{
    public function __construct(
        private DatabaseConnectionTargetResolver $resolver,
    ) {}

    public function resolve(
        string $target,
        ?string $connectionSlug = null,
    ): DatabaseConnection|DatabaseConnectionRegistryFailure {
        $instance = $this->resolver->resolveInstanceSelector($target);

        if ($instance instanceof Instance) {
            return $this->resolveForOwner(
                $target,
                'instance',
                'instance_id',
                $instance->id,
                $connectionSlug,
            );
        }

        $workspace = $this->resolver->resolveWorkspace($target);

        if ($workspace instanceof Workspace) {
            return $this->resolveForOwner($target, 'workspace', 'workspace_id', $workspace->id, $connectionSlug);
        }

        if ($connectionSlug !== null) {
            return DatabaseConnectionRegistryFailure::validation(
                'connection',
                $connectionSlug,
                'The --connection option can only select a connection attached to an instance or workspace target.',
                ['target' => $target],
            );
        }

        return $this->resolveConnection($target);
    }

    private function resolveConnection(string $slug): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $connection = DatabaseConnection::query()
            ->with(['node', 'targets.instance.app', 'targets.workspace.app'])
            ->where('slug', $slug)
            ->first();

        if (! $connection instanceof DatabaseConnection) {
            return DatabaseConnectionRegistryFailure::notFound($slug);
        }

        return $connection;
    }

    private function resolveForOwner(
        string $target,
        string $ownerType,
        string $ownerColumn,
        int $ownerId,
        ?string $connectionSlug,
    ): DatabaseConnection|DatabaseConnectionRegistryFailure {
        $targets = DatabaseConnectionTarget::query()
            ->with(['connection.node', 'connection.targets.instance.app', 'connection.targets.workspace.app'])
            ->where($ownerColumn, $ownerId)
            ->get();

        if ($connectionSlug !== null) {
            $selected = $targets->first(
                fn (DatabaseConnectionTarget $target): bool => $target->connection->slug === $connectionSlug,
            );

            if ($selected instanceof DatabaseConnectionTarget && $selected->connection instanceof DatabaseConnection) {
                return $selected->connection;
            }

            return DatabaseConnectionRegistryFailure::targetConnectionNotFound($ownerType, $ownerId, $connectionSlug);
        }

        if ($targets->isEmpty()) {
            return DatabaseConnectionRegistryFailure::notFound($target);
        }

        if ($targets->count() === 1) {
            $selected = $targets->first();

            if ($selected instanceof DatabaseConnectionTarget && $selected->connection instanceof DatabaseConnection) {
                return $selected->connection;
            }

            return DatabaseConnectionRegistryFailure::notFound($target);
        }

        $default = $targets->first(fn (DatabaseConnectionTarget $target): bool => $target->env_prefix === 'DB');

        if ($default instanceof DatabaseConnectionTarget) {
            return $default->connection;
        }

        return DatabaseConnectionRegistryFailure::ambiguousTarget(
            $target,
            $targets
                ->map(static fn (DatabaseConnectionTarget $target): ?string => $target->connection?->slug)
                ->filter(static fn (?string $slug): bool => is_string($slug))
                ->values()
                ->all(),
        );
    }
}
