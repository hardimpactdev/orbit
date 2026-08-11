<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\Nodes\NodeRoleName;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class DatabaseConnectionProbe
{
    private const array SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const array NETWORK_REQUIRED_SUFFIXES = ['CONNECTION', 'HOST', 'PORT', 'DATABASE', 'USERNAME'];

    private const array SQLITE_REQUIRED_SUFFIXES = ['CONNECTION', 'DATABASE'];

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DatabaseConnectionEnvInspection $envInspection,
        private RemoteEnvFile $remoteEnvFile,
        private DatabaseConnectionTargetEndpointResolver $endpointResolver,
        private NodeWireGuardSelfRouteProbe $wireGuardSelfRouteProbe,
        private WorkspacePlacement $workspacePlacement,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @return list<DoctorIssue>
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

        foreach ($this->instancesForNode($node, $scope) as $scopedInstance) {
            $issues = [...$issues, ...$this->extraIssuesForObservedPrefixes($node, $scopedInstance, $scannedTargets)];
        }

        foreach ($this->workspacesForNode($node, $scope) as $scopedWorkspace) {
            $issues = [...$issues, ...$this->extraIssuesForObservedPrefixes($node, $scopedWorkspace, $scannedTargets)];
        }

        return array_map(
            fn (array $issue): DoctorIssue => $this->doctorIssueFactory->fromArray([
                ...$issue,
                'node' => $node->name,
            ]),
            $issues,
        );
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

        // Unsupported platforms and other non-diagnostic states are not drift.
        if ($diagnostic['supported'] === false) {
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
        $query = DatabaseConnectionTarget::query();

        if ($this->productionNodeExcludesWorkspaces($node)) {
            $query->whereNull('workspace_id');
        }

        foreach ($query
            ->with(['connection.node', 'instance.app', 'workspace.app', 'workspace.instance'])
            ->get() as $target) {
            if (! $this->targetNode($target)->is($node)) {
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
                && $target->instance?->app?->name !== $scope->app
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
                'supported' => $diagnostic['supported'] ?? null,
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
        if ($target->instance instanceof Instance) {
            return [
                'target_type' => 'instance',
                'target_id' => $target->instance->id,
                'app' => $target->instance->app->name,
                'instance' => $target->instance->name,
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
        if ($target->instance instanceof Instance) {
            return $this->instancePath($target->instance);
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
        Instance|Workspace $target,
        array $scannedTargets,
    ): array {
        $path = $target instanceof Instance
            ? $this->instancePath($target)
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
            $detail = $target instanceof Instance
                ? [
                    'target_type' => 'instance',
                    'target_id' => $target->id,
                    'app' => $target->app->name,
                    'instance' => $target->name,
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

            // Local app SQLite is not fleet database intent. Partial groups
            // (DB_CONNECTION=sqlite only) and complete local paths must not
            // become unverifiable or env_extra drift for doctor/adoption.
            $observedDriver = $values["{$prefix}_CONNECTION"] ?? null;

            if ($observedDriver === 'sqlite') {
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
     * @return list<Instance>
     */
    private function instancesForNode(Node $node, DoctorTargetScope $scope): array
    {
        if ($scope->workspace !== null) {
            return [];
        }

        $instances = [];

        foreach (Instance::query()->with('app')->get() as $instance) {
            if (! $instance instanceof Instance) {
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
            ->with(['app', 'instance']);

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

    private function instancePath(Instance $instance): ?string
    {
        $config = $instance->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData || ! is_string($config->path)) {
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
