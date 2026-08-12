<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use App\Services\Workspaces\WorkspacePlacement;
use Throwable;

final readonly class DoctorDatabaseConnectionRestorer
{
    public function __construct(
        private DatabaseConnectionRestorer $databaseConnectionRestorer,
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(string $key, array $detail): ?array
    {
        if (! array_key_exists($key, DatabaseConnectionRestorer::restoreSupport())) {
            return null;
        }

        $reference = DoctorDatabaseConnectionTargetReference::fromDetail($detail);

        if (! $reference instanceof DoctorDatabaseConnectionTargetReference) {
            return null;
        }

        $target = DatabaseConnectionTarget::query()
            ->with(['instance.app', 'workspace.instance.app'])
            ->where('env_prefix', $reference->envPrefix)
            ->when($reference->type === 'instance', static fn ($query) => $query->where('instance_id', $reference->id))
            ->when($reference->type === 'workspace', static fn ($query) => $query->where(
                'workspace_id',
                $reference->id,
            ))
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            if ($key !== 'database_connection.target_missing') {
                return null;
            }

            return $this->restoreMissingTarget($key, $detail, $reference);
        }

        $nodeName = $this->targetNode($target)?->name;

        try {
            $this->databaseConnectionRestorer->restore($target);
        } catch (Throwable $throwable) {
            return [
                'family' => 'database_connection',
                'node' => $nodeName,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    'error' => $throwable->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'database_connection',
            'node' => $nodeName,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function issueTargetsWorkspace(array $detail): bool
    {
        return DoctorDatabaseConnectionTargetReference::detailTargetsWorkspace($detail);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function restoreMissingTarget(
        string $key,
        array $detail,
        DoctorDatabaseConnectionTargetReference $reference,
    ): ?array {
        if ($reference->databaseConnectionId === null) {
            return null;
        }

        $connection = DatabaseConnection::query()->find($reference->databaseConnectionId);

        if (! $connection instanceof DatabaseConnection) {
            return null;
        }

        DatabaseConnectionTarget::query()->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => $reference->envPrefix,
            'instance_id' => $reference->type === 'instance' ? $reference->id : null,
            'workspace_id' => $reference->type === 'workspace' ? $reference->id : null,
        ]);

        return [
            'family' => 'database_connection',
            'node' => $this->targetNodeName($reference->type, $reference->id),
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    private function targetNodeName(string $targetType, int $targetId): ?string
    {
        if ($targetType === 'instance') {
            $instance = Instance::query()->with('app')->find($targetId);

            return $instance instanceof Instance
                ? $this->workspacePlacement->nodeForInstance($instance)?->name
                : null;
        }

        if ($targetType !== 'workspace') {
            return null;
        }

        $workspace = Workspace::query()
            ->with(['instance.app'])
            ->find($targetId);

        return $workspace instanceof Workspace
            ? $this->workspacePlacement->nodeForWorkspace($workspace)?->name
            : null;
    }

    private function targetNode(DatabaseConnectionTarget $target): ?Node
    {
        if ($target->instance instanceof Instance) {
            return $this->workspacePlacement->nodeForInstance($target->instance);
        }

        if ($target->workspace instanceof Workspace) {
            return $this->workspacePlacement->nodeForWorkspace($target->workspace);
        }

        return null;
    }
}
