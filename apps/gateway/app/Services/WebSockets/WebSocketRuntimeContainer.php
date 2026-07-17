<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use InvalidArgumentException;

class WebSocketRuntimeContainer
{
    public const SourceTarget = '/app';

    public const SourceHostPath = '/opt/orbit/websocket/current';

    public const SpecHashLabel = 'orbit.websocket.spec_hash';

    /** @var array<string, string> */
    private readonly array $environment;

    /** @var list<array{source: string, target: string, read_only: bool}> */
    private readonly array $mounts;

    /** @var list<string> */
    private readonly array $networkAliases;

    /** @var list<string> */
    private readonly array $publishedPorts;

    /**
     * @param  array<string, string>  $environment
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @param  list<string>  $networkAliases
     * @param  list<string>  $publishedPorts
     */
    public function __construct(
        private readonly string $name,
        private readonly string $image,
        private readonly string $network,
        private readonly string $restartPolicy,
        private readonly string $backendName,
        private readonly int $valkeyNodeId,
        private readonly string $workingDirectory,
        private readonly string $command,
        array $environment,
        array $mounts,
        array $networkAliases,
        array $publishedPorts = [],
    ) {
        $this->environment = $this->normalizeEnvironment($environment);
        $this->mounts = $this->normalizeMounts($mounts);
        $this->networkAliases = $this->normalizeNetworkAliases($networkAliases);
        $this->publishedPorts = $this->normalizePublishedPorts($publishedPorts);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function image(): string
    {
        return $this->image;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function restartPolicy(): string
    {
        return $this->restartPolicy;
    }

    public function backendName(): string
    {
        return $this->backendName;
    }

    public function valkeyNodeId(): int
    {
        return $this->valkeyNodeId;
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function command(): string
    {
        return $this->command;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /** @return list<string> */
    public function networkAliases(): array
    {
        return $this->networkAliases;
    }

    /** @return list<string> */
    public function publishedPorts(): array
    {
        return $this->publishedPorts;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'websocket-runtime',
            'orbit.websocket.backend' => $this->backendName,
            self::SpecHashLabel => $this->specHash(),
        ];

        ksort($labels);

        return $labels;
    }

    public function specHash(): string
    {
        return hash('sha256', json_encode($this->spec(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     backend_name: string,
     *     valkey_node_id: int,
     *     working_directory: string,
     *     command: string,
     *     environment: array<string, string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     published_ports: list<string>
     * }
     */
    public function spec(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'network' => $this->network,
            'restart_policy' => $this->restartPolicy,
            'backend_name' => $this->backendName,
            'valkey_node_id' => $this->valkeyNodeId,
            'working_directory' => $this->workingDirectory,
            'command' => $this->command,
            'environment' => $this->environment,
            'mounts' => $this->mounts,
            'network_aliases' => $this->networkAliases,
            'published_ports' => $this->publishedPorts,
        ];
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function normalizeEnvironment(array $environment): array
    {
        ksort($environment);

        return $environment;
    }

    /**
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function normalizeMounts(array $mounts): array
    {
        return array_map(function (array $mount): array {
            $source = trim($mount['source']);
            $target = trim($mount['target']);

            if ($source === '' || $target === '') {
                throw new InvalidArgumentException(
                    'WebSocket runtime container mounts require source and target paths.',
                );
            }

            return [
                'source' => $source,
                'target' => $target,
                'read_only' => $mount['read_only'] ?? false,
            ];
        }, $mounts);
    }

    /**
     * @param  list<string>  $networkAliases
     * @return list<string>
     */
    private function normalizeNetworkAliases(array $networkAliases): array
    {
        $aliases = array_values(array_unique(array_filter(
            array_map(trim(...), $networkAliases),
            fn (string $alias): bool => $alias !== '',
        )));

        sort($aliases);

        return $aliases;
    }

    /**
     * @param  list<string>  $publishedPorts
     * @return list<string>
     */
    private function normalizePublishedPorts(array $publishedPorts): array
    {
        $ports = array_values(array_unique(array_map(trim(...), $publishedPorts)));

        foreach ($ports as $port) {
            if (preg_match('/^[0-9a-fA-F:.]+:\d{1,5}:\d{1,5}(?:\/(?:tcp|udp))?$/', $port) !== 1) {
                throw new InvalidArgumentException("Invalid WebSocket runtime published port: {$port}");
            }
        }

        sort($ports);

        return $ports;
    }
}
