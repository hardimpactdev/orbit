<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\DoctorTargetScope;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class DatabaseConnectionProbe
{
    private const array SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const array NETWORK_REQUIRED_SUFFIXES = ['CONNECTION', 'HOST', 'PORT', 'DATABASE', 'USERNAME'];

    private const array SQLITE_REQUIRED_SUFFIXES = ['CONNECTION', 'DATABASE'];

    public function __construct(
        private DatabaseConnectionEnvInspection $envInspection,
        private RemoteEnvFile $remoteEnvFile,
        private DatabaseConnectionTargetEndpointResolver $endpointResolver,
        private NodeWireGuardSelfRouteProbe $wireGuardSelfRouteProbe,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function probe(Node $node, ?DoctorTargetScope $scope = null): array
    {
        $scope ??= DoctorTargetScope::none();
        $issues = [];
        $scannedTargets = [];

        foreach ($this->targetsForNode($node, $scope) as $target) {
            $contents = $this->readEnvContents($node, $target);

            if ($contents === null) {
                $issues[] = $this->issue('database_connection.unverifiable', 'extra', $target, [
                    'reason' => 'env_unreadable',
                ]);

                continue;
            }

            $observed = $this->envInspection->parse($contents);
            $scannedTargets[] = $this->detailKey($this->targetDetail($target));
            $expected = $this->expectedEnvValues($target);
            $missing = array_keys(array_diff_key($expected, $observed));
            $mismatched = [];

            foreach (array_intersect_key($expected, $observed) as $key => $value) {
                if ($observed[$key] !== $value) {
                    $mismatched[$key] = $this->isSecretKey($key)
                        ? 'masked'
                        : [
                            'expected' => $value,
                            'observed' => $observed[$key],
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

            $selfRouteIssue = $this->wireGuardSelfRouteIssue($target, $expected);

            if ($selfRouteIssue !== null) {
                $issues[] = $selfRouteIssue;
            }
        }

        foreach ($this->appInstancesForNode($node, $scope) as $scopedInstance) {
            $issues = [...$issues, ...$this->extraIssuesForObservedPrefixes($node, $scopedInstance, $scannedTargets)];
        }

        foreach ($this->workspacesForNode($node, $scope) as $scopedWorkspace) {
            $issues = [...$issues, ...$this->extraIssuesForObservedPrefixes($node, $scopedWorkspace, $scannedTargets)];
        }

        return $issues;
    }

    private function readEnvContents(Node $node, DatabaseConnectionTarget $target): ?string
    {
        $path = $this->envPath($target);

        if ($path === null) {
            return null;
        }

        if ($this->shouldUseLocalFilesystem($node) && is_file($path)) {
            $contents = file_get_contents($path);

            return is_string($contents) ? $contents : null;
        }

        return $this->remoteEnvFile->read($node, $path);
    }

    /**
     * @return array<string, string>
     */
    private function expectedEnvValues(DatabaseConnectionTarget $target): array
    {
        $connection = $target->connection;
        $endpoint = $this->endpointResolver->forTarget($target);

        return $this->envInspection->expectedValues(
            $target,
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

    /**
     * @param  array<string, string>  $expected
     * @return array<string, mixed>|null
     */
    private function wireGuardSelfRouteIssue(DatabaseConnectionTarget $target, array $expected): ?array
    {
        $connection = $target->connection;

        if ($connection->driver === 'sqlite' || ! $connection->node instanceof Node) {
            return null;
        }

        $targetNode = $this->targetNode($target);

        if (! $connection->node->is($targetNode)) {
            return null;
        }

        $wireGuardAddress = trim((string) $targetNode->wireguard_address);
        $host = $expected["{$target->env_prefix}_HOST"] ?? null;

        if ($wireGuardAddress === '' || $host !== $wireGuardAddress) {
            return null;
        }

        $diagnostic = $this->wireGuardSelfRouteProbe->probe($targetNode);

        if (($diagnostic['ok'] ?? false) === true) {
            return null;
        }

        return $this->issue('database_connection.wireguard_self_route_unavailable', 'unverifiable', $target, [
            'connection' => $connection->slug,
            'node' => $targetNode->name,
            'host' => $host,
            ...$this->wireGuardSelfRouteDetail($diagnostic),
        ]);
    }

    /**
     * @return list<DatabaseConnectionTarget>
     */
    private function targetsForNode(Node $node, DoctorTargetScope $scope): array
    {
        $targets = [];

        foreach (DatabaseConnectionTarget::query()
            ->with(['connection.node', 'appInstance.app', 'workspace.app', 'workspace.appInstance'])
            ->get() as $target) {
            if (! $target instanceof DatabaseConnectionTarget || ! $this->targetNode($target)->is($node)) {
                continue;
            }

            if (
                $scope->workspace !== null
                && ($target->workspace?->name !== $scope->workspace
                || $scope->app !== null
                && $target->workspace?->app?->name !== $scope->app)
            ) {
                continue;
            }

            if (
                $scope->workspace === null
                && $scope->app !== null
                && $target->appInstance?->app?->name !== $scope->app
                && $target->workspace?->app?->name !== $scope->app
            ) {
                continue;
            }

            $targets[] = $target;
        }

        return $targets;
    }

    private function targetNode(DatabaseConnectionTarget $target): Node
    {
        if ($target->appInstance instanceof AppInstance) {
            $node = $this->workspacePlacement->nodeForInstance($target->appInstance);

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

        throw new \RuntimeException('Database connection target has no owning node.');
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function wireGuardSelfRouteDetail(array $diagnostic): array
    {
        return array_filter(
            [
                'wireguard_address' => $diagnostic['wireguard_address'] ?? null,
                'platform' => $diagnostic['platform'] ?? null,
                'reason' => $diagnostic['reason'] ?? null,
                'message' => $diagnostic['message'] ?? null,
                'command' => $diagnostic['command'] ?? null,
                'exit_code' => $diagnostic['exit_code'] ?? null,
                'output' => $diagnostic['output'] ?? null,
            ],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
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
        if ($target->appInstance instanceof AppInstance) {
            return [
                'target_type' => 'app_instance',
                'target_id' => $target->appInstance->id,
                'app' => $target->appInstance->app->name,
                'app_instance' => $target->appInstance->name,
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
        if ($target->appInstance instanceof AppInstance) {
            return $this->appInstancePath($target->appInstance);
        }

        if ($target->workspace instanceof Workspace) {
            return rtrim($target->workspace->path, '/').'/.env';
        }

        return null;
    }

    /**
     * @param  list<string>  $scannedTargets
     * @return list<array<string, mixed>>
     */
    private function extraIssuesForObservedPrefixes(
        Node $node,
        AppInstance|Workspace $target,
        array $scannedTargets,
    ): array {
        $path = $target instanceof AppInstance
            ? $this->appInstancePath($target)
            : rtrim($target->path, '/').'/.env';

        if ($path === null) {
            return [];
        }
        $contents = $this->shouldUseLocalFilesystem($node) && is_file($path)
            ? file_get_contents($path)
            : $this->remoteEnvFile->read($node, $path);

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $values = $this->envInspection->parse($contents);
        $issues = [];

        foreach ($this->observedPrefixes($values) as $prefix) {
            $detail = $target instanceof AppInstance
                ? [
                    'target_type' => 'app_instance',
                    'target_id' => $target->id,
                    'app' => $target->app->name,
                    'app_instance' => $target->name,
                    'env_prefix' => $prefix,
                ]
                : [
                    'target_type' => 'workspace',
                    'target_id' => $target->id,
                    'workspace' => $target->name,
                    'app' => $target->app?->name,
                    'env_prefix' => $prefix,
                ];

            if (in_array($this->detailKey($detail), $scannedTargets, true)) {
                continue;
            }

            $payload = $this->payloadFromObservedValues($values, $prefix);

            if (! $payload instanceof DatabaseConnectionPayload) {
                $issues[] = [
                    'family' => 'database_connection',
                    'key' => 'database_connection.unverifiable',
                    'kind' => 'unverifiable',
                    'summary' => 'database_connection.unverifiable',
                    'detail' => [
                        ...$detail,
                        ...$payload,
                    ],
                ];

                continue;
            }

            $connection = $this->matchingConnection($payload, $node);

            if ($connection instanceof DatabaseConnection) {
                $issues[] = [
                    'family' => 'database_connection',
                    'key' => 'database_connection.target_missing',
                    'kind' => 'missing',
                    'summary' => 'database_connection.target_missing',
                    'detail' => [
                        ...$detail,
                        'database_connection_id' => $connection->id,
                        'connection' => $connection->slug,
                    ],
                ];

                continue;
            }

            $issues[] = [
                'family' => 'database_connection',
                'key' => 'database_connection.env_extra',
                'kind' => 'extra',
                'summary' => 'database_connection.env_extra',
                'detail' => $detail,
            ];
        }

        return $issues;
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
     * @return DatabaseConnectionPayload|array<string, mixed>
     */
    private function payloadFromObservedValues(array $values, string $prefix): DatabaseConnectionPayload|array
    {
        $driver = $values["{$prefix}_CONNECTION"] ?? null;

        if (! is_string($driver) || $driver === '') {
            return [
                'reason' => 'missing_connection_driver',
                'missing_keys' => ["{$prefix}_CONNECTION"],
            ];
        }

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return ['reason' => 'unsupported_driver'];
        }

        $missing = $this->missingRequiredKeys($values, $prefix, $driver);

        if ($missing !== []) {
            return [
                'reason' => 'partial_env_group',
                'missing_keys' => $missing,
            ];
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
     * @param  array<string, mixed>  $detail
     */
    private function detailKey(array $detail): string
    {
        return implode(':', [
            (string) ($detail['target_type'] ?? ''),
            (string) ($detail['target_id'] ?? ''),
            (string) ($detail['env_prefix'] ?? ''),
        ]);
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

    private function isSecretKey(string $key): bool
    {
        return str_ends_with($key, '_PASSWORD') || str_contains($key, 'PASSWORD');
    }
}
