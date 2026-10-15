<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeSettings
{
    public function __construct(
        public ?AppsSettings $apps = null,
        public ?TaskVmsSettings $task_vms = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(
            apps: self::nestedApps($data['apps'] ?? null),
            task_vms: is_array($data['task_vms'] ?? null) ? TaskVmsSettings::fromGatewayData($data['task_vms']) : null,
        );
    }

    /**
     * @return array{apps: array{path: string|null}|null, task_vms: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return ['apps' => $this->apps?->toArray(), 'task_vms' => $this->task_vms?->toArray()];
    }

    public function isEmpty(): bool
    {
        return $this->apps === null && $this->task_vms === null;
    }

    private static function nestedApps(mixed $value): ?AppsSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $nested = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $nested[$key] = $item;
        }

        return AppsSettings::fromGatewayData($nested);
    }
}
