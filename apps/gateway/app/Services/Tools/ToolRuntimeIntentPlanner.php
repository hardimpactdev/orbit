<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;

final readonly class ToolRuntimeIntentPlanner
{
    /**
     * @var array<string, array{
     *     command: string,
     *     image: string,
     *     target_port: int,
     *     published_ports: array<array-key, int>,
     *     volume_target: string,
     *     healthcheck: array<string, mixed>
     * }>
     */
    private const array SERVICES = [
        'mysql' => [
            'command' => 'mysqld',
            'image' => 'mysql',
            'target_port' => 3306,
            'published_ports' => [
                '8' => 3308,
                '9' => 3309,
            ],
            'volume_target' => '/var/lib/mysql',
            'healthcheck' => [
                'command' => 'mysqladmin ping',
            ],
        ],
        'postgres' => [
            'command' => 'postgres',
            'image' => 'postgres',
            'target_port' => 5432,
            'published_ports' => [
                '16' => 5432,
            ],
            'volume_target' => '/var/lib/postgresql/data',
            'healthcheck' => [
                'command' => 'pg_isready',
            ],
        ],
        'redis' => [
            'command' => 'redis-server --bind 0.0.0.0 --protected-mode no',
            'image' => 'redis',
            'target_port' => 6379,
            'published_ports' => [
                '7' => 6379,
            ],
            'volume_target' => '/data',
            'healthcheck' => [
                'command' => 'redis-cli ping',
            ],
        ],
    ];

    public function __construct(
        private ToolRuntimeDriverRegistry $drivers,
    ) {}

    public function plan(
        Node $node,
        ToolInstanceSelector $instance,
        ToolRuntimeSelection|ToolRegistryFailure $runtime,
    ): ToolRuntimeIntent|ToolRegistryFailure {
        if ($runtime instanceof ToolRegistryFailure) {
            return $runtime;
        }

        $driver = $this->drivers->resolve($runtime);

        if ($driver instanceof ToolRegistryFailure) {
            return $driver;
        }

        $definition = self::SERVICES[$instance->tool] ?? null;

        if ($definition === null) {
            return ToolRegistryFailure::runtimeUnsupported($instance->tool, $runtime->runtime);
        }

        $processName = $this->processName($instance);
        $duplicate = $this->duplicateIntent($node, $instance, $processName);

        if ($duplicate instanceof ToolRegistryFailure) {
            return $duplicate;
        }

        $intent = $this->intent($node, $instance, $runtime, $definition, $processName);
        $endpointConflict = $this->endpointConflict($node, $intent);

        if ($endpointConflict instanceof ToolRegistryFailure) {
            return $endpointConflict;
        }

        return $intent;
    }

    /**
     * @param  array{
     *     command: string,
     *     image: string,
     *     target_port: int,
     *     published_ports: array<array-key, int>,
     *     volume_target: string,
     *     healthcheck: array<string, mixed>
     * }  $definition
     */
    private function intent(
        Node $node,
        ToolInstanceSelector $instance,
        ToolRuntimeSelection $runtime,
        array $definition,
        string $processName,
    ): ToolRuntimeIntent {
        $version = $this->versionSuffix($instance);
        $serviceName = "orbit-{$instance->tool}-{$version}";
        $host = $this->host($node);
        $publishedPort = $definition['published_ports'][$version] ?? $definition['target_port'];

        return new ToolRuntimeIntent(
            tool: $instance->tool,
            instanceKey: $instance->instanceKey,
            versionFamily: $instance->versionFamily,
            expectedVersion: $instance->expectedVersion,
            runtime: $runtime->runtime,
            implementationKey: $runtime->implementationKey,
            processName: $processName,
            processRuntime: $runtime->runtime,
            serviceName: $serviceName,
            image: "{$definition['image']}:{$this->imageVersion($instance)}",
            command: $definition['command'],
            endpoint: [
                'name' => "{$instance->tool}-{$version}",
                'kind' => 'tcp',
                'host' => $host,
                'port' => $publishedPort,
            ],
            ports: [
                [
                    'host' => $host,
                    'published' => $publishedPort,
                    'target' => $definition['target_port'],
                    'protocol' => 'tcp',
                ],
            ],
            volumes: [
                [
                    'name' => $serviceName,
                    'target' => $definition['volume_target'],
                ],
            ],
            healthcheck: $definition['healthcheck'],
            updateStrategy: [
                'order' => 'stop-first',
                'parallelism' => 1,
            ],
        );
    }

    private function duplicateIntent(Node $node, ToolInstanceSelector $instance, string $processName): ?ToolRegistryFailure
    {
        $existingTool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', $instance->tool)
            ->where('instance_key', $instance->instanceKey)
            ->exists();

        if ($existingTool) {
            return ToolRegistryFailure::instanceExists(
                node: $node->name,
                tool: $instance->tool,
                instance: $instance->instanceKey,
                source: 'node_tools',
            );
        }

        $existingProcess = Process::query()
            ->where('node_id', $node->id)
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->where('name', $processName)
            ->exists();

        if (! $existingProcess) {
            return null;
        }

        return ToolRegistryFailure::instanceExists(
            node: $node->name,
            tool: $instance->tool,
            instance: $instance->instanceKey,
            source: 'processes',
            process: $processName,
        );
    }

    private function endpointConflict(Node $node, ToolRuntimeIntent $intent): ?ToolRegistryFailure
    {
        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->get();

        foreach ($tools as $tool) {
            foreach ($this->endpoints($tool->config) as $endpoint) {
                if (($endpoint['host'] ?? null) !== $intent->endpoint['host']) {
                    continue;
                }

                if ((int) ($endpoint['port'] ?? 0) !== $intent->endpoint['port']) {
                    continue;
                }

                return ToolRegistryFailure::endpointConflict(
                    node: $node->name,
                    tool: $intent->tool,
                    instance: $intent->instanceKey,
                    host: $intent->endpoint['host'],
                    port: $intent->endpoint['port'],
                    existingTool: (string) $tool->name,
                    existingInstance: is_string($tool->instance_key) ? $tool->instance_key : null,
                );
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function endpoints(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $endpoints = $config['endpoints'] ?? null;

        return is_array($endpoints) ? array_values(array_filter($endpoints, is_array(...))) : [];
    }

    private function processName(ToolInstanceSelector $instance): string
    {
        return $this->slug($instance->tool.$this->versionSuffix($instance));
    }

    private function versionSuffix(ToolInstanceSelector $instance): string
    {
        if ($instance->versionFamily !== null && trim($instance->versionFamily) !== '') {
            return trim($instance->versionFamily);
        }

        if (str_contains($instance->instanceKey, ':')) {
            [, $suffix] = explode(':', $instance->instanceKey, 2);
            $suffix = trim($suffix);

            if ($suffix !== '' && $suffix !== 'default') {
                return $suffix;
            }
        }

        return 'default';
    }

    private function imageVersion(ToolInstanceSelector $instance): string
    {
        return $instance->expectedVersion
            ?? $instance->versionFamily
            ?? $this->versionSuffix($instance);
    }

    private function host(Node $node): string
    {
        $wireguardAddress = is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';

        if ($wireguardAddress !== '') {
            return $wireguardAddress;
        }

        return (string) $node->host;
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $value));

        return $slug !== '' ? $slug : 'service';
    }
}
