<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Contracts\RemoteShell;
use App\Data\Doctor\AdoptResult;
use App\Enums\AdoptAction;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Support\Str;

final readonly class DatabaseConnectionAdopter
{
    private const SUPPORTED_PREFIXES = ['DB', 'ANALYTICS_DB'];

    public function __construct(
        private EnvFileEditor $envFileEditor,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node): array
    {
        $results = [];

        foreach ($this->workspacesForNode($node) as $workspace) {
            foreach ($this->payloadsFromEnvPath($node, rtrim($workspace->path, '/').'/.env') as $prefix => $payload) {
                $target = DatabaseConnectionTarget::query()
                    ->with('connection')
                    ->where('workspace_id', $workspace->id)
                    ->where('env_prefix', $prefix)
                    ->first();
                $baseSlug = sprintf(
                    '%s-%s%s',
                    Str::slug($workspace->name),
                    Str::slug($workspace->app->name),
                    $prefix === 'DB' ? '' : '-'.Str::slug($prefix)
                );

                [$connection, $action, $key] = $this->persistObservedConnection($target, $baseSlug, $payload);

                DatabaseConnectionTarget::query()->updateOrCreate(
                    ['workspace_id' => $workspace->id, 'env_prefix' => $prefix],
                    ['database_connection_id' => $connection->id, 'app_id' => null],
                );

                $results[] = new AdoptResult(
                    family: 'database_connection',
                    key: $key,
                    action: $action,
                    summary: "Adopted database connection for workspace '{$workspace->name}'.",
                    detail: ['target_type' => 'workspace', 'target_id' => $workspace->id, 'workspace' => $workspace->name, 'app' => $workspace->app->name, 'env_prefix' => $prefix],
                );
            }
        }

        foreach ($this->appsForNode($node) as $app) {
            foreach ($this->payloadsFromEnvPath($node, rtrim($app->path, '/').'/.env') as $prefix => $payload) {
                $target = DatabaseConnectionTarget::query()
                    ->with('connection')
                    ->where('app_id', $app->id)
                    ->where('env_prefix', $prefix)
                    ->first();
                $baseSlug = sprintf(
                    '%s%s',
                    Str::slug($app->name),
                    $prefix === 'DB' ? '' : '-'.Str::slug($prefix)
                );

                [$connection, $action, $key] = $this->persistObservedConnection($target, $baseSlug, $payload);

                DatabaseConnectionTarget::query()->updateOrCreate(
                    ['app_id' => $app->id, 'env_prefix' => $prefix],
                    ['database_connection_id' => $connection->id, 'workspace_id' => null],
                );

                $results[] = new AdoptResult(
                    family: 'database_connection',
                    key: $key,
                    action: $action,
                    summary: "Adopted database connection for app '{$app->name}'.",
                    detail: ['target_type' => 'app', 'target_id' => $app->id, 'app' => $app->name, 'env_prefix' => $prefix],
                );
            }
        }

        return $results;
    }

    /**
     * @return array<string, DatabaseConnectionPayload>
     */
    private function payloadsFromEnvPath(Node $node, string $path): array
    {
        $contents = $this->shouldUseLocalFilesystem($node) && is_file($path)
            ? file_get_contents($path)
            : $this->remoteShell->run($node, sprintf('test -f %1$s && cat %1$s', escapeshellarg($path)), ['throw' => false])->stdout;

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $values = $this->envFileEditor->parse($contents);
        $payloads = [];

        foreach (self::SUPPORTED_PREFIXES as $prefix) {
            $driver = $values["{$prefix}_CONNECTION"] ?? null;

            if (! is_string($driver) || $driver === '') {
                continue;
            }

            $payload = DatabaseConnectionPayload::fromArray([
                'driver' => $driver,
                'host' => $values["{$prefix}_HOST"] ?? null,
                'port' => $values["{$prefix}_PORT"] ?? null,
                'database' => $values["{$prefix}_DATABASE"] ?? null,
                'path' => $driver === 'sqlite' ? ($values["{$prefix}_DATABASE"] ?? null) : null,
                'username' => $values["{$prefix}_USERNAME"] ?? null,
                'password' => $values["{$prefix}_PASSWORD"] ?? null,
            ]);

            if (! $this->payloadHasMeaningfulValues($payload)) {
                continue;
            }

            $payloads[$prefix] = $payload;
        }

        return $payloads;
    }

    private function upsertConnection(string $slug, DatabaseConnectionPayload $payload): DatabaseConnection
    {
        return DatabaseConnection::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'driver' => $payload->driver,
                'host' => $payload->host,
                'port' => $payload->port,
                'database' => $payload->database,
                'path' => $payload->path,
                'username' => $payload->username,
                'credentials' => $payload->credentials(),
            ],
        );
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while (DatabaseConnection::query()->where('slug', $slug)->exists()) {
            $slug = sprintf('%s-%d', $base, $suffix);
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array{0: DatabaseConnection, 1: AdoptAction, 2: string}
     */
    private function persistObservedConnection(?DatabaseConnectionTarget $target, string $baseSlug, DatabaseConnectionPayload $payload): array
    {
        if ($target?->connection instanceof DatabaseConnection) {
            $target->connection->fill([
                'driver' => $payload->driver,
                'host' => $payload->host ?? $target->connection->host,
                'port' => $payload->port ?? $target->connection->port,
                'database' => $payload->database ?? $target->connection->database,
                'path' => $payload->path ?? $target->connection->path,
                'username' => $payload->username ?? $target->connection->username,
                'credentials' => $this->mergeCredentials($target->connection, $payload),
            ])->save();

            return [$target->connection->fresh(), AdoptAction::Updated, 'database_connection.env_mismatch'];
        }

        if (! $this->payloadHasMeaningfulValues($payload)) {
            throw new \RuntimeException('Unreachable empty payload.');
        }

        $connection = $this->upsertConnection(
            slug: $this->uniqueSlug($baseSlug),
            payload: $payload,
        );

        return [$connection, AdoptAction::Created, 'database_connection.target_extra'];
    }

    private function payloadHasMeaningfulValues(DatabaseConnectionPayload $payload): bool
    {
        if ($payload->driver === 'sqlite') {
            return ($payload->path ?? $payload->database) !== null;
        }

        return $payload->host !== null
            || $payload->port !== null
            || $payload->database !== null
            || $payload->username !== null
            || $payload->password !== null;
    }

    /**
     * @return array{password?: string}
     */
    private function mergeCredentials(DatabaseConnection $connection, DatabaseConnectionPayload $payload): array
    {
        $credentials = is_array($connection->credentials) ? $connection->credentials : [];

        if ($payload->password !== null) {
            $credentials['password'] = $payload->password;
        }

        return $credentials;
    }

    /**
     * @return list<App>
     */
    private function appsForNode(Node $node): array
    {
        return App::query()->where('node_id', $node->id)->get()->all();
    }

    /**
     * @return list<Workspace>
     */
    private function workspacesForNode(Node $node): array
    {
        return Workspace::query()->with('app')->whereHas('app', fn ($query) => $query->where('node_id', $node->id))->get()->all();
    }

    private function shouldUseLocalFilesystem(Node $node): bool
    {
        return $node->role === 'gateway';
    }
}
