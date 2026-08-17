<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class AnalyticsProcessEndpointResolver
{
    public function __construct(
        private AnalyticsDatabaseResolver $analyticsDatabaseResolver,
    ) {}

    /**
     * @return array{process: Process, host: string, port: int}
     */
    public function resolve(
        NodeRoleAssignment $assignment,
        string $nodeIdSetting,
        string $service,
        ?string $processIdSetting = null,
    ): array {
        $settings = $this->stringKeyedArray($assignment->settings);
        $nodeId = $settings[$nodeIdSetting] ?? null;

        if (! is_int($nodeId) || $nodeId < 1) {
            throw new RuntimeException("The analytics role requires a {$nodeIdSetting} setting.");
        }

        $node = Node::query()->find($nodeId);

        if (! $node instanceof Node) {
            throw new RuntimeException("The analytics role requires an existing {$nodeIdSetting} node.");
        }

        $processQuery = Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->getKey())
            ->where('runtime_config->service', $service);

        $processId = $processIdSetting !== null ? $settings[$processIdSetting] ?? null : null;

        if (is_int($processId) && $processId > 0) {
            /** @var Process|null $process */
            $process = (clone $processQuery)->whereKey($processId)->first();
        } else {
            $processes = $processQuery->orderBy('id')->get();

            if ($processIdSetting !== null && $processes->count() > 1) {
                $serviceLabel = $service === 'postgres' ? 'PostgreSQL' : $service;

                throw new RuntimeException(
                    "The analytics role {$serviceLabel} selection is ambiguous on node {$node->name}; store {$processIdSetting}.",
                );
            }

            if ($processIdSetting !== null) {
                throw new RuntimeException("The analytics role requires a {$processIdSetting} setting.");
            }

            /** @var Process|null $process */
            $process = $processes->first();
        }

        if (! $process instanceof Process) {
            if ($processIdSetting !== null && is_int($processId)) {
                throw new RuntimeException(
                    "The analytics role {$processIdSetting} must reference a {$service} process on its assigned database node.",
                );
            }

            throw new RuntimeException(
                "The analytics role requires a {$service} process on its assigned database node.",
            );
        }

        if (
            $service === 'postgres'
            && ! $this->analyticsDatabaseResolver->isPlausiblePostgresProcess($process)
        ) {
            throw new RuntimeException('The analytics role requires PostgreSQL 16 for Plausible.');
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

    /** @return array{database: string, username: string, password: string} */
    public function credentials(Process $process, string $context): array
    {
        $credentials = $this->stringKeyedArray($process->credentials);

        return [
            'database' => $this->requiredString($credentials, 'database', $context),
            'username' => $this->requiredString($credentials, 'username', $context),
            'password' => $this->requiredString($credentials, 'password', $context),
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
