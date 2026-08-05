<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Doctor\DoctorRestoreActionId;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class DatabaseConnectionRestorer
{
    /**
     * Codes applyDatabaseConnectionIssue can restore when target identity is present.
     *
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            'database_connection.env_missing',
            'database_connection.env_mismatch',
            'database_connection.target_missing',
        ]);
    }

    public function __construct(
        private EnvFileEditor $envFileEditor,
        private DatabaseConnectionEnvMapper $envMapper,
        private RemoteEnvFile $remoteEnvFile,
        private DatabaseConnectionTargetEndpointResolver $endpointResolver,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    public function restore(DatabaseConnectionTarget $target): void
    {
        $path = $this->envPath($target);

        if ($path === null) {
            throw new RuntimeException('Database connection target has no readable path.');
        }

        $contents = $this->readContents($target, $path);
        $updated = $this->envFileEditor->update($contents, $this->expectedEnvValues($target));

        if ($this->shouldUseLocalFilesystem($target) && is_file($path)) {
            file_put_contents($path, $updated);

            return;
        }

        $this->remoteEnvFile->write($this->targetNode($target), $path, $updated);
    }

    /**
     * @return array<string, string>
     */
    private function expectedEnvValues(DatabaseConnectionTarget $target): array
    {
        $connection = $target->connection;
        $endpoint = $this->endpointResolver->forTarget($target);

        return $this->envMapper->toEnvValues(
            $target->env_prefix,
            DatabaseConnectionPayload::fromArray([
                'driver' => $connection->driver,
                'host' => $endpoint['host'],
                'port' => $endpoint['port'],
                'database' => $connection->database,
                'path' => $connection->path,
                'username' => $connection->username,
                'credentials' => $connection->credentials,
            ]),
        );
    }

    private function readContents(DatabaseConnectionTarget $target, string $path): string
    {
        if ($this->shouldUseLocalFilesystem($target) && is_file($path)) {
            return (string) file_get_contents($path);
        }

        return $this->remoteEnvFile->read($this->targetNode($target), $path) ?? '';
    }

    private function envPath(DatabaseConnectionTarget $target): ?string
    {
        if ($target->instance instanceof Instance) {
            $config = $target->instance->driver_config;

            return $config instanceof OrbitInstanceDriverConfigData && is_string($config->path)
                ? rtrim($config->path, '/').'/.env'
                : null;
        }

        if ($target->workspace instanceof Workspace) {
            return rtrim($target->workspace->path, '/').'/.env';
        }

        return null;
    }

    private function shouldUseLocalFilesystem(DatabaseConnectionTarget $target): bool
    {
        $node = $this->targetNode($target);

        return $node->hasActiveRole('gateway');
    }

    private function targetNode(DatabaseConnectionTarget $target): Node
    {
        if ($target->instance instanceof Instance) {
            $node = $this->workspacePlacement->nodeForInstance($target->instance);

            if ($node instanceof Node) {
                return $node;
            }
        }

        if ($target->workspace instanceof Workspace) {
            $node = $this->workspacePlacement->nodeForWorkspace($target->workspace);

            if ($node instanceof Node) {
                return $node;
            }
        }

        throw new RuntimeException('Database connection target has no owning node.');
    }
}
