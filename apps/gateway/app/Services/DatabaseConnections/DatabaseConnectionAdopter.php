<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\AdoptAction;
use App\Enums\Nodes\NodeRoleName;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Str;

final readonly class DatabaseConnectionAdopter
{
    private const array SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const array NETWORK_REQUIRED_SUFFIXES = ['CONNECTION', 'HOST', 'PORT', 'DATABASE', 'USERNAME'];

    private const array SQLITE_REQUIRED_SUFFIXES = ['CONNECTION', 'DATABASE'];

    public function __construct(
        private EnvFileEditor $envFileEditor,
        private RemoteEnvFile $remoteEnvFile,
        private DatabaseConnectionTargetEndpointResolver $endpointResolver,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ?DoctorTargetScope $scope = null): array
    {
        $scope ??= DoctorTargetScope::none();
        $results = [];

        foreach ($this->workspacesForNode($node, $scope) as $scopedWorkspace) {
            foreach ($this->payloadsFromEnvPath(
                $node,
                rtrim($scopedWorkspace->path, '/').'/.env',
            ) as $prefix => $payload) {
                $target = DatabaseConnectionTarget::query()
                    ->with('connection')
                    ->where('workspace_id', $scopedWorkspace->id)
                    ->where('env_prefix', $prefix)
                    ->first();
                $baseSlug = sprintf(
                    '%s-%s%s',
                    Str::slug($scopedWorkspace->name),
                    Str::slug($scopedWorkspace->app?->name),
                    $prefix === 'DB' ? '' : '-'.Str::slug($prefix),
                );

                [$connection, $action, $key] = $this->persistObservedConnection($target, $baseSlug, $payload, $node);

                DatabaseConnectionTarget::query()->updateOrCreate(
                    ['workspace_id' => $scopedWorkspace->id, 'env_prefix' => $prefix],
                    ['database_connection_id' => $connection->id, 'app_instance_id' => null],
                );

                $results[] = new AdoptResult(
                    family: 'database_connection',
                    key: $key,
                    action: $action,
                    summary: "Adopted database connection for workspace '{$scopedWorkspace->name}'.",
                    detail: [
                        'target_type' => 'workspace',
                        'target_id' => $scopedWorkspace->id,
                        'workspace' => $scopedWorkspace->name,
                        'app' => $scopedWorkspace->app?->name,
                        'env_prefix' => $prefix,
                    ],
                );
            }
        }

        foreach ($this->appInstancesForNode($node, $scope) as $scopedInstance) {
            $path = $this->appInstancePath($scopedInstance);

            if ($path === null) {
                continue;
            }

            foreach ($this->payloadsFromEnvPath($node, $path) as $prefix => $payload) {
                $target = DatabaseConnectionTarget::query()
                    ->with('connection')
                    ->where('app_instance_id', $scopedInstance->id)
                    ->where('env_prefix', $prefix)
                    ->first();
                $baseSlug = sprintf(
                    '%s-%s%s',
                    Str::slug($scopedInstance->app->name),
                    Str::slug($scopedInstance->name),
                    $prefix === 'DB' ? '' : '-'.Str::slug($prefix),
                );

                [$connection, $action, $key] = $this->persistObservedConnection($target, $baseSlug, $payload, $node);

                DatabaseConnectionTarget::query()->updateOrCreate(
                    ['app_instance_id' => $scopedInstance->id, 'env_prefix' => $prefix],
                    ['database_connection_id' => $connection->id, 'workspace_id' => null],
                );

                $results[] = new AdoptResult(
                    family: 'database_connection',
                    key: $key,
                    action: $action,
                    summary: "Adopted database connection for instance '{$scopedInstance->app->name}.{$scopedInstance->name}'.",
                    detail: [
                        'target_type' => 'app_instance',
                        'target_id' => $scopedInstance->id,
                        'app' => $scopedInstance->app->name,
                        'app_instance' => $scopedInstance->name,
                        'env_prefix' => $prefix,
                    ],
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
            : $this->remoteEnvFile->read($node, $path);

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $values = $this->envFileEditor->parse($contents);
        $payloads = [];

        foreach ($this->observedPrefixes($values) as $prefix) {
            $payload = $this->payloadFromObservedValues($values, $prefix);

            if (! $payload instanceof DatabaseConnectionPayload) {
                continue;
            }

            if (! $this->payloadHasMeaningfulValues($payload)) {
                continue;
            }

            $payloads[$prefix] = $payload;
        }

        return $payloads;
    }

    private function upsertConnection(string $slug, DatabaseConnectionPayload $payload, Node $node): DatabaseConnection
    {
        return DatabaseConnection::query()->updateOrCreate(
            ['slug' => $slug],
            $this->attributesFromPayload($payload, node: $node),
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
    private function persistObservedConnection(
        ?DatabaseConnectionTarget $target,
        string $baseSlug,
        DatabaseConnectionPayload $payload,
        Node $node,
    ): array {
        $targetConnection = $target instanceof DatabaseConnectionTarget
            ? $target->connection()->first()
            : null;

        if ($targetConnection instanceof DatabaseConnection) {
            if ($this->connectionMatchesPayload($targetConnection, $payload, $node)) {
                return [
                    $this->freshConnection($targetConnection),
                    AdoptAction::Updated,
                    'database_connection.env_mismatch',
                ];
            }

            $targetConnection->fill($this->attributesFromPayload($payload, $targetConnection, $node))->save();

            return [
                $this->freshConnection($targetConnection),
                AdoptAction::Updated,
                'database_connection.env_mismatch',
            ];
        }

        if (! $this->payloadHasMeaningfulValues($payload)) {
            throw new \RuntimeException('Unreachable empty payload.');
        }

        $connection = $this->matchingConnection($payload, $node) ?? $this->upsertConnection(
            slug: $this->uniqueSlug($baseSlug),
            payload: $payload,
            node: $node,
        );

        return [$connection, AdoptAction::Created, 'database_connection.target_extra'];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFromPayload(
        DatabaseConnectionPayload $payload,
        ?DatabaseConnection $existing = null,
        ?Node $node = null,
    ): array {
        $credentials = $this->mergeCredentials($existing, $payload);

        if ($payload->driver === 'sqlite') {
            return [
                'node_id' => $node instanceof Node ? $node->id : $existing?->node_id,
                'driver' => $payload->driver,
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => $payload->path ?? $existing?->path,
                'username' => null,
                'credentials' => $credentials,
            ];
        }

        return [
            'driver' => $payload->driver,
            'host' => $payload->host ?? $existing?->host,
            'port' => $payload->port ?? $existing?->port,
            'database' => $payload->database ?? $existing?->database,
            'path' => null,
            'username' => $payload->username ?? $existing?->username,
            'credentials' => $credentials,
        ];
    }

    private function payloadHasMeaningfulValues(DatabaseConnectionPayload $payload): bool
    {
        if ($payload->driver === 'sqlite') {
            return ($payload->path ?? $payload->database) !== null;
        }

        return (
            $payload->host !== null
            || $payload->port !== null
            || $payload->database !== null
            || $payload->username !== null
            || $payload->password !== null
        );
    }

    /**
     * @return array{password?: string}
     */
    private function mergeCredentials(?DatabaseConnection $connection, DatabaseConnectionPayload $payload): array
    {
        $credentials = $connection?->credentials;

        if (! is_array($credentials)) {
            $credentials = [];
        }

        if ($payload->password !== null) {
            $credentials['password'] = $payload->password;
        }

        return (
            is_string($credentials['password'] ?? null)
                ? ['password' => $credentials['password']]
                : []
        );
    }

    private function freshConnection(DatabaseConnection $connection): DatabaseConnection
    {
        $fresh = $connection->fresh();

        if (! $fresh instanceof DatabaseConnection) {
            throw new \RuntimeException('Database connection no longer exists.');
        }

        return $fresh;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function observedPrefixes(array $values): array
    {
        $prefixes = [];

        foreach ($values as $key => $value) {
            $ending = '_CONNECTION';

            if (! str_ends_with($key, $ending)) {
                continue;
            }

            $prefix = substr($key, 0, -strlen($ending));

            if ($this->validEnvPrefix($prefix) && in_array($value, self::SUPPORTED_DRIVERS, true)) {
                $prefixes[] = $prefix;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function payloadFromObservedValues(array $values, string $prefix): ?DatabaseConnectionPayload
    {
        $driver = $values["{$prefix}_CONNECTION"] ?? null;

        if (! is_string($driver) || $driver === '' || ! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return null;
        }

        if ($this->missingRequiredKeys($values, $prefix, $driver) !== []) {
            return null;
        }

        return DatabaseConnectionPayload::fromArray([
            'driver' => $driver,
            'host' => $driver === 'sqlite' ? null : $values["{$prefix}_HOST"] ?? null,
            'port' => $driver === 'sqlite' ? null : $values["{$prefix}_PORT"] ?? null,
            'database' => $driver === 'sqlite' ? null : $values["{$prefix}_DATABASE"] ?? null,
            'path' => $driver === 'sqlite' ? $values["{$prefix}_DATABASE"] ?? null : null,
            'username' => $driver === 'sqlite' ? null : $values["{$prefix}_USERNAME"] ?? null,
            'password' => $values["{$prefix}_PASSWORD"] ?? null,
        ]);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function missingRequiredKeys(array $values, string $prefix, string $driver): array
    {
        $requiredSuffixes = $driver === 'sqlite'
            ? self::SQLITE_REQUIRED_SUFFIXES
            : self::NETWORK_REQUIRED_SUFFIXES;

        $missing = [];

        foreach ($requiredSuffixes as $suffix) {
            $key = "{$prefix}_{$suffix}";

            if (! is_string($values[$key] ?? null) || $values[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function matchingConnection(DatabaseConnectionPayload $payload, Node $node): ?DatabaseConnection
    {
        $connections = DatabaseConnection::query()
            ->where('driver', $payload->driver)
            ->get();

        foreach ($connections as $connection) {
            if ($this->connectionMatchesPayload($connection, $payload, $node)) {
                return $connection;
            }
        }

        return null;
    }

    private function connectionMatchesPayload(
        DatabaseConnection $connection,
        DatabaseConnectionPayload $payload,
        Node $node,
    ): bool {
        if ($payload->driver === 'sqlite') {
            return $connection->node_id === $node->id && $connection->path === $payload->path;
        }

        $password = $connection->credentials['password'] ?? null;
        try {
            $endpoint = $this->endpointResolver->forConnectionOnNode($connection, $node);
        } catch (\RuntimeException) {
            return false;
        }

        return (
            $endpoint['host'] === $payload->host
            && $endpoint['port'] === $payload->port
            && $connection->database === $payload->database
            && $connection->username === $payload->username
            && (! is_string($payload->password) || $password === $payload->password)
        );
    }

    private function validEnvPrefix(string $value): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1;
    }

    /**
     * @return list<AppInstance>
     */
    private function appInstancesForNode(Node $node, DoctorTargetScope $scope): array
    {
        if ($scope->workspace !== null) {
            return [];
        }

        $instances = [];

        foreach (AppInstance::query()->with('app')->get() as $instance) {
            if (! $instance instanceof AppInstance) {
                continue;
            }

            if ($this->workspacePlacement->nodeForInstance($instance)?->is($node) !== true) {
                continue;
            }

            if ($scope->app !== null && $instance->app->name !== $scope->app) {
                continue;
            }

            $instances[] = $instance;
        }

        return $instances;
    }

    /**
     * @return list<Workspace>
     */
    private function workspacesForNode(Node $node, DoctorTargetScope $scope): array
    {
        if ($this->productionNodeExcludesWorkspaces($node)) {
            return [];
        }

        if ($scope->workspace === null && $scope->app !== null) {
            return [];
        }

        $query = Workspace::query()
            ->with(['app', 'appInstance']);

        if ($scope->workspace !== null) {
            $query->where('name', $scope->workspace);

            if ($scope->app !== null) {
                $query->whereHas('app', static fn ($appQuery) => $appQuery->where('name', $scope->app));
            }
        }

        /** @var list<Workspace> */
        return $query
            ->get()
            ->filter(
                fn (Workspace $workspace): bool => (
                    $this->workspacePlacement->nodeForWorkspace($workspace)?->is($node) === true
                ),
            )
            ->values()
            ->all();
    }

    private function productionNodeExcludesWorkspaces(Node $node): bool
    {
        return $node->hasActiveRole(NodeRoleName::AppProduction->value);
    }

    private function appInstancePath(AppInstance $instance): ?string
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitAppInstanceDriverConfigData || ! is_string($config->path)) {
            return null;
        }

        return rtrim($config->path, '/').'/.env';
    }

    private function shouldUseLocalFilesystem(Node $node): bool
    {
        return $node->hasActiveRole('gateway');
    }
}
