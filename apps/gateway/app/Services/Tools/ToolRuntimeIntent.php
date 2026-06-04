<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use InvalidArgumentException;

final readonly class ToolRuntimeIntent
{
    /** @var array{name: string, kind: string, host: string, port: int} */
    public array $endpoint;

    /** @var list<array{host: string, published: int, target: int, protocol: string}> */
    public array $ports;

    /** @var list<array{name: string, target: string}> */
    public array $volumes;

    /** @var array<string, mixed> */
    public array $healthcheck;

    /** @var array{order: string, parallelism: int} */
    public array $updateStrategy;

    /** @var array<string, string> */
    private array $extraLabels;

    /**
     * @param  array{name: string, kind: string, host: string, port: int}  $endpoint
     * @param  list<array{host: string, published: int, target: int, protocol: string}>  $ports
     * @param  list<array{name: string, target: string}>  $volumes
     * @param  array<string, mixed>  $healthcheck
     * @param  array{order: string, parallelism: int}  $updateStrategy
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $tool,
        public string $instanceKey,
        public ?string $versionFamily,
        public ?string $expectedVersion,
        public string $runtime,
        public string $implementationKey,
        public string $processName,
        public string $processRuntime,
        public string $serviceName,
        public string $image,
        public string $command,
        array $endpoint,
        array $ports,
        array $volumes,
        array $healthcheck,
        array $updateStrategy,
        array $labels = [],
    ) {
        $this->endpoint = $this->normalizeEndpoint($endpoint);
        $this->ports = $this->normalizePorts($ports);
        $this->volumes = $this->normalizeVolumes($volumes);
        $this->healthcheck = $this->sortRecursive($healthcheck);
        $this->updateStrategy = $this->normalizeUpdateStrategy($updateStrategy);
        $this->extraLabels = $this->normalizeLabels($labels);
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.runtime' => $this->runtime,
            'orbit.runtime_implementation' => $this->implementationKey,
            'orbit.tool' => $this->tool,
            'orbit.tool.spec_hash' => $this->specHash(),
            'orbit.tool_instance' => $this->instanceKey,
            ...$this->extraLabels,
        ];

        ksort($labels);

        return $labels;
    }

    public function specHash(): string
    {
        return hash('sha256', json_encode($this->spec(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function spec(): array
    {
        return $this->sortRecursive([
            'command' => $this->command,
            'endpoint' => $this->endpoint,
            'expected_version' => $this->expectedVersion,
            'healthcheck' => $this->healthcheck,
            'image' => $this->image,
            'implementation_key' => $this->implementationKey,
            'instance_key' => $this->instanceKey,
            'labels' => $this->baseLabels(),
            'ports' => $this->ports,
            'process_name' => $this->processName,
            'process_runtime' => $this->processRuntime,
            'runtime' => $this->runtime,
            'service_name' => $this->serviceName,
            'tool' => $this->tool,
            'update_strategy' => $this->updateStrategy,
            'version_family' => $this->versionFamily,
            'volumes' => $this->volumes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function processAttributes(Node $node, int $sortOrder): array
    {
        return [
            'node_id' => $node->id,
            'owner_type' => $node->getMorphClass(),
            'owner_id' => $node->id,
            'name' => $this->processName,
            'command' => $this->command,
            'restart_policy' => ProcessRestartPolicy::OnFailure,
            'crash_notification' => ProcessCrashNotification::None,
            'runtime' => $this->processRuntime,
            'tool' => $this->tool,
            'runtime_config' => [
                'endpoint' => $this->endpoint,
                'healthcheck' => $this->healthcheck,
                'image' => $this->image,
                'implementation_key' => $this->implementationKey,
                'labels' => $this->labels(),
                'ports' => $this->ports,
                'service_name' => $this->serviceName,
                'spec_hash' => $this->specHash(),
                'tool_instance_key' => $this->instanceKey,
                'update_strategy' => $this->updateStrategy,
                'volumes' => $this->volumes,
            ],
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function baseLabels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.runtime' => $this->runtime,
            'orbit.runtime_implementation' => $this->implementationKey,
            'orbit.tool' => $this->tool,
            'orbit.tool_instance' => $this->instanceKey,
            ...$this->extraLabels,
        ];

        ksort($labels);

        return $labels;
    }

    /**
     * @param  array{name?: string, kind?: string, host?: string, port?: int}  $endpoint
     * @return array{name: string, kind: string, host: string, port: int}
     */
    private function normalizeEndpoint(array $endpoint): array
    {
        $name = trim((string) ($endpoint['name'] ?? ''));
        $kind = trim((string) ($endpoint['kind'] ?? ''));
        $host = trim((string) ($endpoint['host'] ?? ''));
        $port = (int) ($endpoint['port'] ?? 0);

        if ($name === '' || $kind === '' || $host === '' || $port < 1) {
            throw new InvalidArgumentException('Tool runtime endpoint requires name, kind, host, and port.');
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * @param  list<array{host?: string, published?: int, target?: int, protocol?: string}>  $ports
     * @return list<array{host: string, published: int, target: int, protocol: string}>
     */
    private function normalizePorts(array $ports): array
    {
        return array_map(fn (array $port): array => [
            'host' => trim((string) ($port['host'] ?? '')),
            'published' => (int) ($port['published'] ?? 0),
            'target' => (int) ($port['target'] ?? 0),
            'protocol' => trim((string) ($port['protocol'] ?? 'tcp')),
        ], $ports);
    }

    /**
     * @param  list<array{name?: string, target?: string}>  $volumes
     * @return list<array{name: string, target: string}>
     */
    private function normalizeVolumes(array $volumes): array
    {
        return array_map(fn (array $volume): array => [
            'name' => trim((string) ($volume['name'] ?? '')),
            'target' => trim((string) ($volume['target'] ?? '')),
        ], $volumes);
    }

    /**
     * @param  array{order?: string, parallelism?: int}  $updateStrategy
     * @return array{order: string, parallelism: int}
     */
    private function normalizeUpdateStrategy(array $updateStrategy): array
    {
        return [
            'order' => trim((string) ($updateStrategy['order'] ?? 'stop-first')),
            'parallelism' => (int) ($updateStrategy['parallelism'] ?? 1),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private function normalizeLabels(array $labels): array
    {
        ksort($labels);

        return $labels;
    }

    /**
     * @template TValue
     *
     * @param  TValue  $value
     * @return TValue
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
