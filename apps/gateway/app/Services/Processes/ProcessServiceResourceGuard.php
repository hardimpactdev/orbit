<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Process;
use Orbit\Sdk\Laravel\GatewayApiException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class ProcessServiceResourceGuard
{
    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    public function assertNoConflicts(
        ProcessOwnerContext $context,
        string $name,
        array $runtimeConfig,
        ?int $ignoreProcessId = null,
    ): void {
        $requestedEndpoints = $this->endpoints($runtimeConfig);
        $requestedVolumeNames = $this->volumeNames($runtimeConfig);

        $query = Process::query()->where('node_id', $context->node->id);

        if ($ignoreProcessId !== null) {
            $query->where('id', '!=', $ignoreProcessId);
        }

        foreach ($query->get() as $process) {
            $config = is_array($process->runtime_config) ? $process->runtime_config : [];

            foreach ($requestedEndpoints as $endpoint) {
                foreach ($this->endpoints($config) as $existingEndpoint) {
                    if (
                        $endpoint['port'] !== $existingEndpoint['port']
                        || $endpoint['host'] !== $existingEndpoint['host']
                    ) {
                        continue;
                    }

                    throw new GatewayApiException(
                        "Process '{$name}' endpoint port {$endpoint['port']} conflicts with process '{$process->name}'.",
                        'validation_failed',
                        [
                            'field' => 'service',
                            'reason' => 'endpoint_conflict',
                            'node' => $context->node->name,
                            'process' => $name,
                            'existing_process' => $process->name,
                            'host' => $endpoint['host'],
                            'port' => $endpoint['port'],
                        ],
                    );
                }
            }

            foreach ($requestedVolumeNames as $volumeName) {
                if (! in_array($volumeName, $this->volumeNames($config), true)) {
                    continue;
                }

                throw new GatewayApiException(
                    "Process '{$name}' volume '{$volumeName}' conflicts with process '{$process->name}'.",
                    'validation_failed',
                    [
                        'field' => 'service',
                        'reason' => 'volume_conflict',
                        'node' => $context->node->name,
                        'process' => $name,
                        'existing_process' => $process->name,
                        'volume' => $volumeName,
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string|null, host: string, port: int}>
     */
    private function endpoints(array $config): array
    {
        $rawEndpoints = [];

        if (is_array($config['endpoint'] ?? null)) {
            $rawEndpoints[] = $config['endpoint'];
        }

        if (is_array($config['endpoints'] ?? null)) {
            foreach ($config['endpoints'] as $endpoint) {
                if (is_array($endpoint)) {
                    $rawEndpoints[] = $endpoint;
                }
            }
        }

        if (is_array($config['ports'] ?? null)) {
            foreach ($config['ports'] as $port) {
                if (! is_array($port)) {
                    continue;
                }

                $rawEndpoints[] = [
                    'name' => $port['name'] ?? null,
                    'host' => $port['host'] ?? null,
                    'port' => $port['published'] ?? null,
                ];
            }
        }

        $endpoints = [];

        foreach ($rawEndpoints as $endpoint) {
            $port = (int) ($endpoint['port'] ?? 0);
            $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

            if ($host === '' || $port < 1) {
                continue;
            }

            $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;

            $endpoints[] = [
                'name' => $name !== '' ? $name : null,
                'host' => $host,
                'port' => $port,
            ];
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function volumeNames(array $config): array
    {
        $volumes = [];

        foreach (['mounts', 'volumes'] as $key) {
            if (! is_array($config[$key] ?? null)) {
                continue;
            }

            foreach ($config[$key] as $volume) {
                if (! is_array($volume)) {
                    continue;
                }

                foreach (['name', 'source'] as $nameKey) {
                    $name = is_string($volume[$nameKey] ?? null) ? trim($volume[$nameKey]) : '';

                    if ($name !== '') {
                        $volumes[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($volumes));
    }
}
