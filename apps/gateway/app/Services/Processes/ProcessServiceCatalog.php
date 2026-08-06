<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\NodeWireGuardServiceAddress;
use Illuminate\Support\Str;
use Orbit\Sdk\Laravel\GatewayApiException;
use RuntimeException;

final readonly class ProcessServiceCatalog
{
    public function __construct(
        private NodeWireGuardServiceAddress $serviceAddress,
        private NodeHostPaths $hostPaths,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->services());
    }

    public function supports(string $service): bool
    {
        return array_key_exists($service, $this->services());
    }

    /**
     * @param  array<string, mixed>  $serviceOptions
     * @param  list<string>|null  $binds
     */
    public function resolve(
        string $service,
        ?string $version,
        ProcessRuntime $runtime,
        Node $node,
        string $processName,
        ?string $imageOverride = null,
        array $serviceOptions = [],
        ?array $binds = null,
    ): ProcessServiceDescriptor {
        $catalog = $this->services();
        $entry = $catalog[$service] ?? null;

        if (! is_array($entry)) {
            throw new GatewayApiException("Managed service '{$service}' is not supported.", 'validation_failed', [
                'field' => 'service',
                'value' => $service,
                'reason' => 'unsupported_value',
                'allowed' => $this->names(),
            ]);
        }

        $allowedRuntimes = $this->allowedRuntimes($entry, $node);

        if (! in_array($runtime, $allowedRuntimes, true)) {
            throw new GatewayApiException(
                "Managed service '{$service}' does not support runtime '{$runtime->value}'.",
                'validation_failed',
                [
                    'field' => 'runtime',
                    'value' => $runtime->value,
                    'reason' => 'process_service_runtime_unsupported',
                    'service' => $service,
                    'allowed' => array_map(
                        static fn (ProcessRuntime $runtime): string => $runtime->value,
                        $allowedRuntimes,
                    ),
                ],
            );
        }

        $serviceOptions = $this->resolveServiceOptions($service, $entry, $serviceOptions);
        /** @var array<array-key, array{default: string, versions: list<string>, port: int, data_path?: string}> $versions */
        $versions = $entry['versions'];
        $resolved = $this->resolveVersion($service, $versions, $version);
        $entry = $this->withServiceOptions($service, $entry, $serviceOptions);

        if ($resolved['data_path'] !== null) {
            $entry['data_path'] = $resolved['data_path'];
        }

        $publishedPort = is_int($serviceOptions['published_port'] ?? null)
            ? $serviceOptions['published_port']
            : $resolved['published_port'];
        $this->assertImageMatchesVersionFamily($service, $resolved['family'], $imageOverride);
        // null keeps the WireGuard-only default without rejecting Docker Swarm or
        // other non-Docker managed runtimes. Only an explicit bind list is gated.
        $normalizedBinds = $this->normalizeBinds($binds, $runtime);
        $bindHosts = $this->bindHosts($node, $normalizedBinds);
        $serviceName = "orbit-{$processName}";
        $volumeName = "orbit-{$processName}";
        $dataPath = $this->hostPaths->processDataRoot($node, $processName);
        $servicePorts = $this->servicePorts($entry, $bindHosts, $publishedPort, $processName, $runtime);
        $credentials = $this->encryptedCredentials($service, $entry);

        $runtimeConfig = [
            'service' => $service,
            'version_family' => $resolved['family'],
            'version' => $resolved['version'],
            'endpoint' => $servicePorts['endpoint'],
            'endpoints' => $servicePorts['endpoints'],
            'service_name' => $serviceName,
            'environment' => $entry['environment'],
            'network_aliases' => array_values(array_unique([$service, $processName])),
            'healthcheck' => $entry['healthcheck'],
            'update_strategy' => [
                'order' => 'stop-first',
                'parallelism' => 1,
            ],
        ];

        // Persist bind intent only for Docker managed services. Swarm and other
        // admitted service runtimes keep WireGuard endpoint hosts without a
        // stored bind selector when the public flag is omitted.
        if ($runtime === ProcessRuntime::Docker) {
            $runtimeConfig['binds'] = $normalizedBinds;
        }

        if ($serviceOptions !== []) {
            $runtimeConfig['service_options'] = $serviceOptions;
        }

        if ($credentials === []) {
            $runtimeConfig['credentials'] = $entry['credentials'];
        }

        if ($credentials !== []) {
            $runtimeConfig['credential_hash'] = substr(
                string: hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR)),
                offset: 0,
                length: 16,
            );
        }

        if ($imageOverride !== null && $imageOverride !== '') {
            $runtimeConfig['image'] = $imageOverride;
        } elseif (is_string($entry['image'] ?? null) && $entry['image'] !== '') {
            $imageVersionPrefix = is_string($entry['image_version_prefix'] ?? null)
                ? $entry['image_version_prefix']
                : '';
            $runtimeConfig['image'] = "{$entry['image']}:{$imageVersionPrefix}{$resolved['version']}";
        }

        if (is_string($entry['command_mode'] ?? null) && $entry['command_mode'] !== '') {
            $runtimeConfig['command_mode'] = $entry['command_mode'];
        }

        if ($servicePorts['ports'] !== []) {
            $runtimeConfig['ports'] = $servicePorts['ports'];
        } elseif (is_int($entry['target_port'] ?? null)) {
            $runtimeConfig['ports'] = $this->publishPortsForHosts(
                hosts: $bindHosts,
                published: $resolved['published_port'],
                target: $entry['target_port'],
                protocol: 'tcp',
                runtime: $runtime,
            );
        }

        if (is_string($entry['data_path'] ?? null) && $entry['data_path'] !== '') {
            $runtimeConfig['mounts'] = [
                [
                    'source' => $dataPath,
                    'target' => $entry['data_path'],
                ],
            ];
            $runtimeConfig['volumes'] = [
                [
                    'name' => $volumeName,
                    'target' => $entry['data_path'],
                ],
            ];
        }

        $specHash = $this->hashRuntimeSpec([
            ...$runtimeConfig,
            'runtime' => $runtime->value,
            'process' => $processName,
        ]);

        $runtimeConfig['spec_hash'] = $specHash;
        $runtimeConfig['labels'] = [
            'orbit.managed' => 'true',
            'orbit.process' => $processName,
            'orbit.process.service' => $service,
            'orbit.process.version_family' => $resolved['family'],
            'orbit.process.version' => $resolved['version'],
            'orbit.process.spec_hash' => $specHash,
        ];

        return new ProcessServiceDescriptor(
            service: $service,
            versionFamily: $resolved['family'],
            version: $resolved['version'],
            command: $entry['command'],
            runtimeConfig: $runtimeConfig,
            credentials: $credentials,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(): array
    {
        return [
            'mysql' => [
                'runtimes' => [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
                'image' => 'mysql',
                'command_mode' => 'image_entrypoint',
                'command' => 'mysqld',
                'target_port' => 3306,
                'data_path' => '/var/lib/mysql',
                'environment' => [
                    'MYSQL_DATABASE' => 'orbit',
                    'MYSQL_PASSWORD' => 'orbit',
                    'MYSQL_ROOT_PASSWORD' => 'orbit',
                    'MYSQL_USER' => 'orbit',
                ],
                'credentials' => [
                    'database' => 'orbit',
                    'password' => 'orbit',
                    'username' => 'orbit',
                ],
                'healthcheck' => [
                    'command' => 'mysqladmin ping -horbit -porbit',
                    'kind' => 'command',
                ],
                'versions' => [
                    '8' => [
                        'default' => '8.4',
                        'versions' => ['8.3', '8.4'],
                        'port' => 3308,
                    ],
                    '9' => [
                        'default' => '9',
                        'versions' => ['9'],
                        'port' => 3309,
                    ],
                ],
            ],
            'mailpit' => [
                'runtimes' => [ProcessRuntime::Docker],
                'image' => 'axllent/mailpit',
                'command_mode' => 'image_entrypoint',
                'command' => '/mailpit',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'wget -qO- http://127.0.0.1:8025/livez >/dev/null',
                    'kind' => 'command',
                ],
                'service_ports' => [
                    [
                        'name' => 'smtp',
                        'published' => 1025,
                        'target' => 1025,
                        'protocol' => 'tcp',
                        'primary' => true,
                    ],
                    [
                        'name' => 'ui',
                        'target' => 8025,
                        'protocol' => 'tcp',
                        'endpoint' => false,
                        'publish' => false,
                    ],
                ],
                'versions' => [
                    'latest' => [
                        'default' => 'latest',
                        'versions' => ['latest'],
                        'port' => 1025,
                    ],
                ],
            ],
            'valkey' => [
                'runtimes' => [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
                'image' => 'valkey/valkey',
                'command' => 'valkey-server --appendonly yes --bind 0.0.0.0 --protected-mode no',
                'target_port' => 6379,
                'data_path' => '/data',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'valkey-cli ping',
                    'kind' => 'command',
                ],
                'versions' => [
                    '8' => [
                        'default' => '8.1',
                        'versions' => ['8.1'],
                        'port' => 6379,
                    ],
                ],
            ],
            'prometheus' => [
                'runtimes' => [ProcessRuntime::DockerSwarm],
                'image' => 'prom/prometheus',
                'command' => 'prometheus --config.file=/etc/prometheus/prometheus.yml --storage.tsdb.path=/prometheus --storage.tsdb.retention.time=15d --web.listen-address=0.0.0.0:9090',
                'target_port' => 9090,
                'data_path' => '/prometheus',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'wget -qO- http://127.0.0.1:9090/-/ready >/dev/null',
                    'kind' => 'command',
                ],
                'versions' => [
                    '3' => [
                        'default' => 'v3.12.0',
                        'versions' => ['v3.12.0'],
                        'port' => 9090,
                    ],
                ],
            ],
            'grafana' => [
                'runtimes' => [ProcessRuntime::DockerSwarm],
                'image' => 'grafana/grafana',
                'command_mode' => 'image_entrypoint',
                'command' => '/run.sh',
                'target_port' => 3000,
                'data_path' => '/var/lib/grafana',
                'environment' => [
                    'GF_SECURITY_ADMIN_USER' => 'admin',
                    'GF_SERVER_ROOT_URL' => 'https://metrics.orbit',
                ],
                'credentials' => [
                    'admin_user' => 'admin',
                ],
                'healthcheck' => [
                    'command' => 'wget -qO- http://127.0.0.1:3000/api/health >/dev/null',
                    'kind' => 'command',
                ],
                'versions' => [
                    '13' => [
                        'default' => '13.0.2',
                        'versions' => ['13.0.2'],
                        'port' => 3000,
                    ],
                ],
            ],
            'node-exporter' => [
                'runtimes' => [ProcessRuntime::Systemd],
                'command' => '/usr/local/bin/node_exporter --web.listen-address=0.0.0.0:9100',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'curl -fsS http://127.0.0.1:9100/metrics >/dev/null',
                    'kind' => 'command',
                ],
                'versions' => [
                    '1' => [
                        'default' => '1.11.1',
                        'versions' => ['1.11.1'],
                        'port' => 9100,
                    ],
                ],
            ],
            'postgres' => [
                'runtimes' => [ProcessRuntime::Docker],
                'image' => 'postgres',
                'command_mode' => 'image_entrypoint',
                'command' => 'postgres',
                'target_port' => 5432,
                'data_path' => '/var/lib/postgresql/data',
                'environment' => [],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'pg_isready',
                    'kind' => 'command',
                ],
                'versions' => [
                    '16' => [
                        'default' => '16-alpine',
                        'versions' => ['16-alpine'],
                        'port' => 5432,
                        'data_path' => '/var/lib/postgresql/data',
                    ],
                    '17' => [
                        'default' => '17-alpine',
                        'versions' => ['17-alpine'],
                        'port' => 5432,
                        'data_path' => '/var/lib/postgresql/data',
                    ],
                    '18' => [
                        'default' => '18-alpine',
                        'versions' => ['18-alpine'],
                        'port' => 5432,
                        'data_path' => '/var/lib/postgresql',
                    ],
                ],
            ],
            'clickhouse' => [
                'runtimes' => [ProcessRuntime::Docker],
                'image' => 'clickhouse/clickhouse-server',
                'command_mode' => 'image_entrypoint',
                'command' => 'clickhouse-server',
                'target_port' => 8123,
                'data_path' => '/var/lib/clickhouse',
                'environment' => [
                    'CLICKHOUSE_DB' => 'plausible_events_db',
                    'CLICKHOUSE_USER' => 'plausible',
                ],
                'credentials' => [
                    'database' => 'plausible_events_db',
                    'username' => 'plausible',
                ],
                'healthcheck' => [
                    'command' => 'wget --spider -q http://127.0.0.1:8123/ping || exit 1',
                    'kind' => 'command',
                ],
                'versions' => [
                    '24.12' => [
                        'default' => '24.12-alpine',
                        'versions' => ['24.12-alpine'],
                        'port' => 8123,
                    ],
                ],
            ],
            'plausible' => [
                'runtimes' => [ProcessRuntime::Docker],
                'image' => 'ghcr.io/plausible/community-edition',
                'image_version_prefix' => 'v',
                'command' => 'sh -c "/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run"',
                'target_port' => 8000,
                'environment' => [
                    'BASE_URL' => 'https://analytics.orbit',
                ],
                'credentials' => [],
                'healthcheck' => [
                    'command' => 'wget --spider -q http://127.0.0.1:8000 || exit 1',
                    'kind' => 'command',
                ],
                'versions' => [
                    '3.2.1' => [
                        'default' => '3.2.1',
                        'versions' => ['3.2.1'],
                        'port' => 8000,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function encryptedCredentials(string $service, array $entry): array
    {
        if (! in_array($service, ['postgres', 'clickhouse'], strict: true)) {
            return [];
        }

        $credentials = is_array($entry['credentials'] ?? null) ? $entry['credentials'] : [];
        $password = Str::random(48);
        $passwordEnvironment = $service === 'postgres'
            ? ['POSTGRES_PASSWORD' => $password]
            : ['CLICKHOUSE_PASSWORD' => $password];

        return [
            ...$credentials,
            'password' => $password,
            'environment' => $passwordEnvironment,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $serviceOptions
     * @return array<string, mixed>
     */
    private function resolveServiceOptions(string $service, array $entry, array $serviceOptions): array
    {
        if ($service !== 'postgres') {
            return $this->resolveSinglePortServiceOptions($service, $entry, $serviceOptions);
        }

        foreach (array_keys($serviceOptions) as $key) {
            if (! in_array($key, ['database', 'username', 'published_port'], true)) {
                throw new GatewayApiException('PostgreSQL service option is not supported.', 'validation_failed', [
                    'field' => "service_options.{$key}",
                    'reason' => 'unsupported_field',
                ]);
            }
        }

        foreach (['database', 'username'] as $key) {
            $value = $serviceOptions[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new GatewayApiException("PostgreSQL {$key} is required.", 'validation_failed', [
                    'field' => "service_options.{$key}",
                    'reason' => 'required',
                ]);
            }

            $value = trim($value);

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $value) !== 1) {
                throw new GatewayApiException("PostgreSQL {$key} is not a valid identifier.", 'validation_failed', [
                    'field' => "service_options.{$key}",
                    'value' => $value,
                    'reason' => 'invalid_postgres_identifier',
                ]);
            }

            $serviceOptions[$key] = $value;
        }

        $publishedPort = $serviceOptions['published_port'] ?? null;

        if (! is_int($publishedPort) || $publishedPort < 1 || $publishedPort > 65535) {
            throw new GatewayApiException(
                'PostgreSQL published port must be between 1 and 65535.',
                'validation_failed',
                [
                    'field' => 'service_options.published_port',
                    'value' => $publishedPort,
                    'reason' => $publishedPort === null ? 'required' : 'out_of_range',
                ],
            );
        }

        return $serviceOptions;
    }

    /**
     * Non-postgres managed services accept only a published-port override,
     * and only when the service exposes exactly one endpoint port. Without an
     * override the catalog default port forces every additional instance on a
     * node into an endpoint conflict.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $serviceOptions
     * @return array<string, mixed>
     */
    private function resolveSinglePortServiceOptions(string $service, array $entry, array $serviceOptions): array
    {
        if ($serviceOptions === []) {
            return [];
        }

        $singlePort = is_int($entry['target_port'] ?? null) && ! is_array($entry['service_ports'] ?? null);

        if (! $singlePort || array_keys($serviceOptions) !== ['published_port']) {
            throw new GatewayApiException(
                "Managed service '{$service}' does not accept PostgreSQL service options.",
                'validation_failed',
                ['field' => 'service_options', 'reason' => 'process_service_options_unsupported'],
            );
        }

        $publishedPort = $serviceOptions['published_port'];

        if (! is_int($publishedPort) || $publishedPort < 1 || $publishedPort > 65535) {
            throw new GatewayApiException(
                'Published port must be between 1 and 65535.',
                'validation_failed',
                [
                    'field' => 'service_options.published_port',
                    'value' => $publishedPort,
                    'reason' => 'out_of_range',
                ],
            );
        }

        return ['published_port' => $publishedPort];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $serviceOptions
     * @return array<string, mixed>
     */
    private function withServiceOptions(string $service, array $entry, array $serviceOptions): array
    {
        if ($service !== 'postgres') {
            return $entry;
        }

        $database = (string) $serviceOptions['database'];
        $username = (string) $serviceOptions['username'];
        $entry['environment'] = [
            'POSTGRES_DB' => $database,
            'POSTGRES_USER' => $username,
        ];
        $entry['credentials'] = [
            'database' => $database,
            'username' => $username,
        ];
        $entry['healthcheck'] = [
            'command' => "pg_isready -U {$username} -d {$database}",
            'kind' => 'command',
        ];

        return $entry;
    }

    private function assertImageMatchesVersionFamily(
        string $service,
        string $versionFamily,
        ?string $imageOverride,
    ): void {
        if ($service !== 'postgres' || $imageOverride === null || $imageOverride === '') {
            return;
        }

        $lastSlash = strrpos($imageOverride, '/');
        $tagSeparator = strrpos($imageOverride, ':');
        $tag =
            $tagSeparator !== false && ($lastSlash === false || $tagSeparator > $lastSlash)
                ? substr($imageOverride, $tagSeparator + 1)
                : '';

        if (preg_match('/^(?<major>\d+)(?:[.-]|$)/', $tag, $matches) === 1 && $matches['major'] === $versionFamily) {
            return;
        }

        throw new GatewayApiException(
            "PostgreSQL image major must match selected version family {$versionFamily}.",
            'validation_failed',
            [
                'field' => 'image',
                'value' => $imageOverride,
                'reason' => 'process_service_image_version_mismatch',
                'version_family' => $versionFamily,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<ProcessRuntime>
     */
    private function allowedRuntimes(array $service, Node $node): array
    {
        $runtimes = $service['runtimes'] ?? null;

        if (! is_array($runtimes) || $runtimes === []) {
            $runtimes = [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm];
        }

        $allowed = array_values(array_filter(
            $runtimes,
            static fn (mixed $runtime): bool => $runtime instanceof ProcessRuntime,
        ));

        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return array_values(array_filter(
                $allowed,
                static fn (ProcessRuntime $runtime): bool => $runtime !== ProcessRuntime::DockerSwarm,
            ));
        }

        return $allowed;
    }

    /**
     * @param  array<array-key, array{default: string, versions: list<string>, port: int, data_path?: string}>  $versions
     * @return array{family: string, version: string, published_port: int, data_path: string|null}
     */
    private function resolveVersion(string $service, array $versions, ?string $version): array
    {
        if ($version === null && count($versions) > 1) {
            throw new GatewayApiException("Managed service '{$service}' requires a version.", 'validation_failed', [
                'field' => 'version',
                'reason' => 'required',
                'service' => $service,
                'allowed' => $this->versionFamilies($versions),
            ]);
        }

        if ($version === null) {
            $familyKey = array_key_first($versions);
            $family = (string) $familyKey;
            $metadata = $versions[$familyKey];

            return [
                'family' => $family,
                'version' => $metadata['default'],
                'published_port' => $metadata['port'],
                'data_path' => $metadata['data_path'] ?? null,
            ];
        }

        foreach ($versions as $familyKey => $metadata) {
            $family = (string) $familyKey;

            if (
                $version === $family
                || $version === $metadata['default']
                || in_array($version, $metadata['versions'], true)
            ) {
                return [
                    'family' => $family,
                    'version' => $version === $family ? $metadata['default'] : $version,
                    'published_port' => $metadata['port'],
                    'data_path' => $metadata['data_path'] ?? null,
                ];
            }
        }

        throw new GatewayApiException(
            "Managed service '{$service}' does not support version '{$version}'.",
            'validation_failed',
            [
                'field' => 'version',
                'value' => $version,
                'reason' => 'unsupported_value',
                'service' => $service,
                'allowed' => $this->versionFamilies($versions),
            ],
        );
    }

    /**
     * @param  array<array-key, array{default: string, versions: list<string>, port: int, data_path?: string}>  $versions
     * @return list<string>
     */
    private function versionFamilies(array $versions): array
    {
        return array_map(static fn (int|string $family): string => (string) $family, array_keys($versions));
    }

    private function serviceHost(Node $node): string
    {
        try {
            return $this->serviceAddress->forServiceOn($node, $node, 'process');
        } catch (RuntimeException) {
            throw new GatewayApiException(
                "Node '{$node->name}' cannot host service process endpoints without a WireGuard address.",
                'validation_failed',
                [
                    'field' => 'node',
                    'value' => $node->name,
                    'reason' => 'wireguard_address_required',
                ],
            );
        }
    }

    /**
     * Normalize publish bind selectors.
     *
     * Omitted binds (`null`) always resolve to WireGuard-only and do not reject
     * Docker Swarm or other non-Docker runtimes. An explicit list is validated
     * and only admitted for Docker managed services.
     *
     * @param  list<string>|null  $binds
     * @return list<string>
     */
    public function normalizeBinds(?array $binds, ProcessRuntime $runtime = ProcessRuntime::Docker): array
    {
        if ($binds === null) {
            return ['wireguard'];
        }

        if ($binds === []) {
            throw new GatewayApiException(
                'At least one publish bind is required.',
                'validation_failed',
                [
                    'field' => 'bind',
                    'reason' => 'required',
                    'allowed' => ['wireguard', 'loopback'],
                ],
            );
        }

        if ($runtime === ProcessRuntime::DockerSwarm) {
            throw new GatewayApiException(
                'Publish binds are only supported for Docker managed services.',
                'validation_failed',
                [
                    'field' => 'bind',
                    'reason' => 'process_bind_requires_docker_runtime',
                    'allowed' => ['wireguard', 'loopback'],
                ],
            );
        }

        if ($runtime !== ProcessRuntime::Docker) {
            throw new GatewayApiException(
                'Publish binds are only supported for node-owned Docker managed services.',
                'validation_failed',
                [
                    'field' => 'bind',
                    'reason' => 'process_bind_requires_node_docker_service',
                    'allowed' => ['wireguard', 'loopback'],
                ],
            );
        }

        $normalized = [];

        foreach ($binds as $bind) {
            if (! is_string($bind) || trim($bind) === '') {
                throw new GatewayApiException(
                    'Publish bind selectors cannot be empty.',
                    'validation_failed',
                    [
                        'field' => 'bind',
                        'reason' => 'required',
                        'allowed' => ['wireguard', 'loopback'],
                    ],
                );
            }

            $bind = trim($bind);

            if (! in_array($bind, ['wireguard', 'loopback'], true)) {
                throw new GatewayApiException(
                    "Publish bind '{$bind}' is not supported.",
                    'validation_failed',
                    [
                        'field' => 'bind',
                        'value' => $bind,
                        'reason' => 'unsupported_value',
                        'allowed' => ['wireguard', 'loopback'],
                    ],
                );
            }

            $normalized[] = $bind;
        }

        $ordered = [];

        foreach (['wireguard', 'loopback'] as $selector) {
            if (in_array($selector, $normalized, true)) {
                $ordered[] = $selector;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $binds
     * @return list<string>
     */
    private function bindHosts(Node $node, array $binds): array
    {
        $hosts = [];

        foreach ($binds as $bind) {
            $hosts[] = match ($bind) {
                'wireguard' => $this->serviceHost($node),
                'loopback' => '127.0.0.1',
                default => throw new GatewayApiException(
                    "Publish bind '{$bind}' is not supported.",
                    'validation_failed',
                    [
                        'field' => 'bind',
                        'value' => $bind,
                        'reason' => 'unsupported_value',
                        'allowed' => ['wireguard', 'loopback'],
                    ],
                ),
            };
        }

        return $hosts;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $hosts
     * @return array{
     *     endpoint: array{name: string, kind: string, host: string, port: int},
     *     endpoints: list<array{name: string, kind: string, host: string, port: int}>,
     *     ports: list<array{host?: string, published: int, target: int, protocol: string}>
     * }
     *
     * @mago-expect lint:halstead
     */
    private function servicePorts(
        array $entry,
        array $hosts,
        int $defaultPublishedPort,
        string $processName,
        ProcessRuntime $runtime,
    ): array {
        $primaryHost = $hosts[0] ?? $this->serviceHostPlaceholder();
        $rawPorts = $entry['service_ports'] ?? null;

        if (! is_array($rawPorts) || $rawPorts === []) {
            $endpoints = $this->endpointsForHosts($processName, $hosts, $defaultPublishedPort);

            return [
                'endpoint' => $endpoints[0],
                'endpoints' => $endpoints,
                'ports' => is_int($entry['target_port'] ?? null)
                    ? $this->publishPortsForHosts(
                        hosts: $hosts,
                        published: $defaultPublishedPort,
                        target: $entry['target_port'],
                        protocol: 'tcp',
                        runtime: $runtime,
                    )
                    : [],
            ];
        }

        $endpoints = [];
        $ports = [];
        $primaryEndpoint = null;

        foreach ($rawPorts as $rawPort) {
            if (! is_array($rawPort)) {
                continue;
            }

            $name = is_string($rawPort['name'] ?? null) ? trim($rawPort['name']) : '';
            $published = (int) ($rawPort['published'] ?? 0);
            $target = (int) ($rawPort['target'] ?? 0);
            $protocol = is_string($rawPort['protocol'] ?? null) ? trim($rawPort['protocol']) : 'tcp';
            $exposesEndpoint = ($rawPort['endpoint'] ?? true) !== false;
            $publishesPort = ($rawPort['publish'] ?? true) !== false;

            if ($name === '' || $target < 1) {
                continue;
            }

            if ($exposesEndpoint && $published > 0) {
                $portEndpoints = $this->endpointsForHosts($name, $hosts, $published);
                $endpoints = [...$endpoints, ...$portEndpoints];

                if (($rawPort['primary'] ?? false) === true && $primaryEndpoint === null) {
                    $primaryEndpoint = $portEndpoints[0];
                }
            }

            if ($publishesPort && $published > 0) {
                $ports = [
                    ...$ports,
                    ...$this->publishPortsForHosts(
                        hosts: $hosts,
                        published: $published,
                        target: $target,
                        protocol: $protocol !== '' ? $protocol : 'tcp',
                        runtime: $runtime,
                    ),
                ];
            }
        }

        if ($endpoints === []) {
            $endpoints = $this->endpointsForHosts($processName, $hosts, $defaultPublishedPort);
        }

        return [
            'endpoint' => $primaryEndpoint ?? $endpoints[0],
            'endpoints' => $endpoints,
            'ports' => $ports,
        ];
    }

    /**
     * @param  list<string>  $hosts
     * @return list<array{name: string, kind: string, host: string, port: int}>
     */
    private function endpointsForHosts(string $name, array $hosts, int $port): array
    {
        $endpoints = [];

        foreach ($hosts as $host) {
            $endpoints[] = [
                'name' => $name,
                'kind' => 'tcp',
                'host' => $host,
                'port' => $port,
            ];
        }

        return $endpoints;
    }

    /**
     * @param  list<string>  $hosts
     * @return list<array{host?: string, published: int, target: int, protocol: string}>
     */
    private function publishPortsForHosts(
        array $hosts,
        int $published,
        int $target,
        string $protocol,
        ProcessRuntime $runtime,
    ): array {
        if ($runtime !== ProcessRuntime::Docker) {
            return [
                [
                    'published' => $published,
                    'target' => $target,
                    'protocol' => $protocol,
                ],
            ];
        }

        $ports = [];

        foreach ($hosts as $host) {
            $ports[] = [
                'host' => $host,
                'published' => $published,
                'target' => $target,
                'protocol' => $protocol,
            ];
        }

        return $ports;
    }

    private function serviceHostPlaceholder(): string
    {
        return '127.0.0.1';
    }

    /**
     * Canonical runtime-spec hash used for managed-service labels and drift.
     * Top-level keys are sorted so callers that rebuild the same input always
     * produce the same digest.
     *
     * @param  array<string, mixed>  $spec
     */
    public function hashRuntimeSpec(array $spec): string
    {
        ksort($spec);

        return substr(string: hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)), offset: 0, length: 16);
    }
}
