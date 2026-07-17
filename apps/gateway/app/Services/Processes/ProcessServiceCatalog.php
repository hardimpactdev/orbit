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

    public function resolve(
        string $service,
        ?string $version,
        ProcessRuntime $runtime,
        Node $node,
        string $processName,
        ?string $imageOverride = null,
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

        $resolved = $this->resolveVersion($service, $entry['versions'], $version);
        $host = $this->serviceHost($node);
        $serviceName = "orbit-{$processName}";
        $volumeName = "orbit-{$processName}";
        $dataPath = $this->hostPaths->processDataRoot($node, $processName);
        $servicePorts = $this->servicePorts(
            $entry,
            $host,
            $resolved['published_port'],
            $processName,
            $runtime,
        );
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
            $runtimeConfig['ports'] = [
                [
                    ...($runtime === ProcessRuntime::Docker ? ['host' => $host] : []),
                    'published' => $resolved['published_port'],
                    'target' => $entry['target_port'],
                    'protocol' => 'tcp',
                ],
            ];
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

        $specHash = $this->specHash([
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
                'environment' => [
                    'POSTGRES_DB' => 'plausible_db',
                    'POSTGRES_USER' => 'orbit',
                ],
                'credentials' => [
                    'database' => 'plausible_db',
                    'username' => 'orbit',
                ],
                'healthcheck' => [
                    'command' => 'pg_isready -U orbit',
                    'kind' => 'command',
                ],
                'versions' => [
                    '16' => [
                        'default' => '16-alpine',
                        'versions' => ['16-alpine'],
                        'port' => 5432,
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
     * @param  array<array-key, array{default: string, versions: list<string>, port: int}>  $versions
     * @return array{family: string, version: string, published_port: int}
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
     * @param  array<array-key, array{default: string, versions: list<string>, port: int}>  $versions
     * @return list<string>
     */
    private function versionFamilies(array $versions): array
    {
        return array_map(
            static fn (int|string $family): string => (string) $family,
            array_keys($versions),
        );
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
     * @param  array<string, mixed>  $entry
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
        string $host,
        int $defaultPublishedPort,
        string $processName,
        ProcessRuntime $runtime,
    ): array {
        $rawPorts = $entry['service_ports'] ?? null;

        if (! is_array($rawPorts) || $rawPorts === []) {
            return [
                'endpoint' => [
                    'name' => $processName,
                    'kind' => 'tcp',
                    'host' => $host,
                    'port' => $defaultPublishedPort,
                ],
                'endpoints' => [
                    [
                        'name' => $processName,
                        'kind' => 'tcp',
                        'host' => $host,
                        'port' => $defaultPublishedPort,
                    ],
                ],
                'ports' => is_int($entry['target_port'] ?? null)
                    ? [
                        [
                            ...($runtime === ProcessRuntime::Docker ? ['host' => $host] : []),
                            'published' => $defaultPublishedPort,
                            'target' => $entry['target_port'],
                            'protocol' => 'tcp',
                        ],
                    ]
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
                $endpoint = [
                    'name' => $name,
                    'kind' => 'tcp',
                    'host' => $host,
                    'port' => $published,
                ];

                $endpoints[] = $endpoint;

                if (($rawPort['primary'] ?? false) === true) {
                    $primaryEndpoint = $endpoint;
                }
            }

            if ($publishesPort && $published > 0) {
                $ports[] = [
                    ...($runtime === ProcessRuntime::Docker ? ['host' => $host] : []),
                    'published' => $published,
                    'target' => $target,
                    'protocol' => $protocol !== '' ? $protocol : 'tcp',
                ];
            }
        }

        if ($endpoints === []) {
            return [
                'endpoint' => [
                    'name' => $processName,
                    'kind' => 'tcp',
                    'host' => $host,
                    'port' => $defaultPublishedPort,
                ],
                'endpoints' => [
                    [
                        'name' => $processName,
                        'kind' => 'tcp',
                        'host' => $host,
                        'port' => $defaultPublishedPort,
                    ],
                ],
                'ports' => [],
            ];
        }

        return [
            'endpoint' => $primaryEndpoint ?? $endpoints[0],
            'endpoints' => $endpoints,
            'ports' => $ports,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specHash(array $spec): string
    {
        ksort($spec);

        return substr(
            string: hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)),
            offset: 0,
            length: 16,
        );
    }
}
