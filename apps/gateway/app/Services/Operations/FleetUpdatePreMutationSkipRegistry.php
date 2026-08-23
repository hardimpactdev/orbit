<?php

declare(strict_types=1);

namespace App\Services\Operations;

final class FleetUpdatePreMutationSkipRegistry
{
    /** @var array<string, array<string, string>> */
    private array $reasons = [];

    public function record(string $operationRunId, string $nodeName, string $reason): void
    {
        $this->reasons[$operationRunId][$nodeName] = $reason;
    }

    public function reason(string $operationRunId, string $nodeName): ?string
    {
        return $this->reasons[$operationRunId][$nodeName] ?? null;
    }

    public function skipped(string $operationRunId, string $nodeName): bool
    {
        return $this->reason($operationRunId, $nodeName) !== null;
    }

    /**
     * @return array<string, string>
     */
    public function forOperation(string $operationRunId): array
    {
        return $this->reasons[$operationRunId] ?? [];
    }

    public function forget(string $operationRunId): void
    {
        unset($this->reasons[$operationRunId]);
    }
}
