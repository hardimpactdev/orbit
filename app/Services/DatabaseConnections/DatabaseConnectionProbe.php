<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;

final readonly class DatabaseConnectionProbe
{
    public function __construct(
        private EnvFileEditor $envFileEditor,
        private DatabaseConnectionEnvMapper $envMapper,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function probe(Node $node): array
    {
        $issues = [];

        foreach ($this->targetsForNode($node) as $target) {
            $contents = $this->readEnvContents($node, $target);

            if ($contents === null) {
                $issues[] = $this->issue('database_connection.unverifiable', 'extra', $target, [
                    'reason' => 'env_unreadable',
                ]);

                continue;
            }

            $observed = $this->envFileEditor->parse($contents);
            $expected = $this->expectedEnvValues($target);
            $missing = array_keys(array_diff_key($expected, $observed));
            $mismatched = [];

            foreach (array_intersect_key($expected, $observed) as $key => $value) {
                if ((string) $observed[$key] !== (string) $value) {
                    $mismatched[$key] = [
                        'expected' => (string) $value,
                        'observed' => (string) $observed[$key],
                    ];
                }
            }

            if ($missing !== []) {
                $issues[] = $this->issue('database_connection.env_missing', 'missing', $target, [
                    'missing_keys' => $missing,
                ]);
            }

            if ($mismatched !== []) {
                $issues[] = $this->issue('database_connection.env_mismatch', 'mismatch', $target, [
                    'mismatched_keys' => $mismatched,
                ]);
            }
        }

        return $issues;
    }

    private function readEnvContents(Node $node, DatabaseConnectionTarget $target): ?string
    {
        $path = $this->envPath($target);

        if ($path === null) {
            return null;
        }

        if (is_file($path)) {
            $contents = file_get_contents($path);

            return is_string($contents) ? $contents : null;
        }

        $script = sprintf(
            'test -f %1$s && cat %1$s',
            escapeshellarg($path),
        );
        $result = $this->remoteShell->run($node, $script, ['throw' => false]);

        return $result->successful() ? $result->stdout : null;
    }

    /**
     * @return array<string, string>
     */
    private function expectedEnvValues(DatabaseConnectionTarget $target): array
    {
        $connection = $target->connection;

        return $this->envMapper->toEnvValues(
            $target->env_prefix,
            DatabaseConnectionPayload::fromArray([
                'driver' => $connection->driver,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'path' => $connection->path,
                'username' => $connection->username,
                'credentials' => $connection->credentials,
            ]),
        );
    }

    /**
     * @return list<DatabaseConnectionTarget>
     */
    private function targetsForNode(Node $node): array
    {
        return DatabaseConnectionTarget::query()
            ->with(['connection', 'app.node', 'workspace.app.node'])
            ->where(function ($query) use ($node): void {
                $query
                    ->whereHas('app', fn ($appQuery) => $appQuery->where('node_id', $node->id))
                    ->orWhereHas('workspace.app', fn ($workspaceQuery) => $workspaceQuery->where('node_id', $node->id));
            })
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function issue(string $key, string $kind, DatabaseConnectionTarget $target, array $detail = []): array
    {
        return [
            'family' => 'database_connection',
            'key' => $key,
            'kind' => $kind,
            'summary' => $key,
            'detail' => [
                ...$this->targetDetail($target),
                ...$detail,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetDetail(DatabaseConnectionTarget $target): array
    {
        if ($target->app instanceof App) {
            return [
                'target_type' => 'app',
                'target_id' => $target->app->id,
                'app' => $target->app->name,
                'env_prefix' => $target->env_prefix,
            ];
        }

        $workspace = $target->workspace;

        return [
            'target_type' => 'workspace',
            'target_id' => $workspace?->id,
            'workspace' => $workspace?->name,
            'app' => $workspace?->app?->name,
            'env_prefix' => $target->env_prefix,
        ];
    }

    private function envPath(DatabaseConnectionTarget $target): ?string
    {
        if ($target->app instanceof App) {
            return rtrim($target->app->path, '/').'/.env';
        }

        if ($target->workspace instanceof Workspace) {
            return rtrim($target->workspace->path, '/').'/.env';
        }

        return null;
    }
}
