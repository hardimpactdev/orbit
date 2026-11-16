<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeSettings
{
    public function __construct(
        public ?AppsSettings $apps = null,
        public ?TaskVmsSettings $task_vms = null,
        public ?CiSettings $ci = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(
            apps: self::nestedApps($data['apps'] ?? null),
            task_vms: is_array($data['task_vms'] ?? null) ? TaskVmsSettings::fromGatewayData($data['task_vms']) : null,
            ci: CiSettings::fromGatewayData($data['ci'] ?? null),
        );
    }

    /**
     * `ci` is left out when the Node has no CI places, as in the Gateway's response.
     *
     * @return array{apps: array{path: string|null}|null, task_vms: array<string, mixed>|null, ci?: array{places:int,proof_only:bool}}
     */
    public function toArray(): array
    {
        return ['apps' => $this->apps?->toArray(), 'task_vms' => $this->task_vms?->toArray(), ...($this->ci === null ? [] : ['ci' => $this->ci->toArray()])];
    }

    public function isEmpty(): bool
    {
        return $this->apps === null && $this->task_vms === null && $this->ci === null;
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
