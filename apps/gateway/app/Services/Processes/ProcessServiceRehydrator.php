<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Node;
use App\Models\Process;
use RuntimeException;

final readonly class ProcessServiceRehydrator
{
    public function __construct(
        private ProcessServiceCatalog $catalog,
    ) {}

    public function resolve(Process $process, Node $node): ProcessServiceDescriptor
    {
        $config = $process->runtime_config;
        $service = $this->optionalString($config['service'] ?? null);

        if ($service === null) {
            throw new RuntimeException("Process '{$process->name}' has no stored managed service intent.");
        }

        return $this->catalog->resolve(
            service: $service,
            version: $this->optionalString($config['version'] ?? null),
            runtime: $process->runtime,
            node: $node,
            processName: $process->name,
            imageOverride: $this->optionalString($config['image'] ?? null),
            serviceOptions: $this->stringKeyedArray($config['service_options'] ?? null),
            binds: $this->optionalStringList($config['binds'] ?? null),
            existingCredentials: is_array($process->credentials) ? $process->credentials : [],
        );
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<string>|null */
    private function optionalStringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
