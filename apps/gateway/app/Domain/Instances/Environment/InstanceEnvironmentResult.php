<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

final readonly class InstanceEnvironmentResult
{
    /** @param list<int>|null $restartedProcessIds */
    public function __construct(
        public int $instanceId,
        public string $operation,
        public bool $changed,
        public int $keyCount,
        public ?InstanceTestEnvironmentOutcome $testing = null,
        public ?string $runtimeRefresh = null,
        public ?bool $environmentChanged = null,
        public ?array $restartedProcessIds = null,
        /** @var list<string> */
        public array $warnings = [],
    ) {}

    /** @return array{instance_id: int, operation: string, changed: bool, key_count: int, runtime_refresh?: string, restarted_process_ids?: list<int>, warnings?: list<string>} */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
            ...($this->runtimeRefresh === null ? [] : ['runtime_refresh' => $this->runtimeRefresh]),
            ...($this->restartedProcessIds === null ? [] : ['restarted_process_ids' => $this->restartedProcessIds]),
            ...($this->operation === 'clone' ? ['warnings' => $this->warnings] : []),
        ];
    }
}
