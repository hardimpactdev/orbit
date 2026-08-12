<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Process;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessRuntimeUnitDetail
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessOwnershipDetail $ownershipDetail,
    ) {}

    /**
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    public function for(Process $process, array $unit): array
    {
        return array_filter(
            [
                'process' => $process->name,
                ...$this->ownershipDetail->for($process),
                'runtime' => $this->runtimeContextResolver->runtime($process)->value,
                'runtime_unit' => $unit['name'] ?? null,
                'expected' => $unit['config_path'] ?? null,
                ...$this->serviceRuntime($process),
            ],
            static fn (mixed $value): bool => $value !== null && (! is_string($value) || $value !== ''),
        );
    }

    /** @return array<string, mixed> */
    public function serviceRuntime(Process $process): array
    {
        $config = $process->runtime_config;
        $service = ProcessRuntimeServiceMetadata::service($config);

        if ($service === null) {
            return [];
        }

        $detail = ['service' => $service];

        foreach (['version_family', 'version', 'service_name'] as $key) {
            $value = $this->optionalConfigString($config, $key);

            if ($value !== null) {
                $detail[$key] = $value;
            }
        }

        $endpoint = $this->serviceEndpoint($config['endpoint'] ?? null);

        if ($endpoint !== null) {
            $detail['endpoint'] = $endpoint;
        }

        return $detail;
    }

    /** @return array{name: string|null, host: string, port: int|null}|null */
    private function serviceEndpoint(mixed $endpoint): ?array
    {
        if (! is_array($endpoint)) {
            return null;
        }

        $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

        if ($host === '') {
            return null;
        }

        $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : '';
        $port = is_numeric($endpoint['port'] ?? null) ? (int) $endpoint['port'] : null;

        return [
            'name' => $name === '' ? null : $name,
            'host' => $host,
            'port' => $port,
        ];
    }

    /** @param array<string, mixed> $config */
    private function optionalConfigString(array $config, string $key): ?string
    {
        if (! is_string($config[$key] ?? null)) {
            return null;
        }

        $value = trim($config[$key]);

        return $value === '' ? null : $value;
    }
}
