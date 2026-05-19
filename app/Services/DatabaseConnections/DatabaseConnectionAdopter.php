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

final readonly class DatabaseConnectionAdopter
{
    private const SUPPORTED_PREFIXES = ['DB'];

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
            $payload = $this->payloadFromEnvPath($node, rtrim($workspace->path, '/').'/.env', 'DB');

            if ($payload === null) {
                continue;
            }

            $connection = $this->upsertConnection(
                slug: $this->uniqueSlug(sprintf('%s-%s', $workspace->name, $workspace->app->name)),
                payload: $payload,
            );
            DatabaseConnectionTarget::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'env_prefix' => 'DB'],
                ['database_connection_id' => $connection->id, 'app_id' => null],
            );

            $results[] = new AdoptResult(
                family: 'database_connection',
                key: 'database_connection.target_extra',
                action: AdoptAction::Created,
                summary: "Adopted database connection for workspace '{$workspace->name}'.",
                detail: ['workspace' => $workspace->name, 'app' => $workspace->app->name, 'env_prefix' => 'DB'],
            );
        }

        foreach ($this->appsForNode($node) as $app) {
            $payload = $this->payloadFromEnvPath($node, rtrim($app->path, '/').'/.env', 'DB');

            if ($payload === null) {
                continue;
            }

            $connection = $this->upsertConnection(
                slug: $this->uniqueSlug($app->name),
                payload: $payload,
            );
            DatabaseConnectionTarget::query()->updateOrCreate(
                ['app_id' => $app->id, 'env_prefix' => 'DB'],
                ['database_connection_id' => $connection->id, 'workspace_id' => null],
            );

            $results[] = new AdoptResult(
                family: 'database_connection',
                key: 'database_connection.target_extra',
                action: AdoptAction::Created,
                summary: "Adopted database connection for app '{$app->name}'.",
                detail: ['app' => $app->name, 'env_prefix' => 'DB'],
            );
        }

        return $results;
    }

    private function payloadFromEnvPath(Node $node, string $path, string $prefix): ?DatabaseConnectionPayload
    {
        $contents = is_file($path)
            ? file_get_contents($path)
            : $this->remoteShell->run($node, sprintf('test -f %1$s && cat %1$s', escapeshellarg($path)), ['throw' => false])->stdout;

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $values = $this->envFileEditor->parse($contents);
        $driver = $values["{$prefix}_CONNECTION"] ?? null;

        if (! is_string($driver) || $driver === '') {
            return null;
        }

        return DatabaseConnectionPayload::fromArray([
            'driver' => $driver,
            'host' => $values["{$prefix}_HOST"] ?? null,
            'port' => $values["{$prefix}_PORT"] ?? null,
            'database' => $values["{$prefix}_DATABASE"] ?? null,
            'path' => $driver === 'sqlite' ? ($values["{$prefix}_DATABASE"] ?? null) : null,
            'username' => $values["{$prefix}_USERNAME"] ?? null,
            'password' => $values["{$prefix}_PASSWORD"] ?? null,
        ]);
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
}
