<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\NodeRoleAssignment;
use App\Models\Process;
use RuntimeException;

final class AnalyticsProcessEndpointResolver
{
    /**
     * @return array{process: Process, host: string, port: int}
     */
    public function resolve(
        NodeRoleAssignment $assignment,
        string $nodeIdSetting,
        string $service,
    ): array {
        $settings = $this->stringKeyedArray($assignment->settings);
        $nodeId = $settings[$nodeIdSetting] ?? null;

        if (! is_int($nodeId) || $nodeId < 1) {
            throw new RuntimeException("The analytics role requires a {$nodeIdSetting} setting.");
        }

        $process = Process::query()
            ->where('node_id', $nodeId)
            ->where('runtime_config->service', $service)
            ->first();

        if (! $process instanceof Process) {
            throw new RuntimeException(
                "The analytics role requires a {$service} process on its assigned database node.",
            );
        }

        $endpoint = $this->stringKeyedArray($process->runtime_config['endpoint'] ?? null);
        $host = $this->requiredString($endpoint, 'host', $process->name);
        $port = $endpoint['port'] ?? null;

        if (! is_int($port) || $port < 1) {
            throw new RuntimeException("Process '{$process->name}' has no usable service endpoint port.");
        }

        return [
            'process' => $process,
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * @return array{username: string, password: string}
     */
    public function postgresCredentials(Process $process): array
    {
        $credentials = $this->stringKeyedArray($process->runtime_config['credentials'] ?? null);

        return [
            'username' => $this->requiredString($credentials, 'username', 'PostgreSQL'),
            'password' => $this->requiredString($credentials, 'password', 'PostgreSQL'),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requiredString(array $config, string $key, string $context): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$context} has no usable {$key} configuration.");
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
