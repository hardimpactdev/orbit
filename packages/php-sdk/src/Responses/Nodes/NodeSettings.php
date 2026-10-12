<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeSettings
{
    /** @param  string|null  $taskWorker  `none` when the Node opts out of the task worker. */
    public function __construct(
        public ?AppsSettings $apps = null,
        public ?string $taskWorker = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        $tasks = $data['tasks'] ?? null;
        $worker = is_array($tasks) ? ($tasks['worker'] ?? null) : null;

        return new self(
            apps: self::nestedApps($data['apps'] ?? null),
            taskWorker: is_string($worker) ? $worker : null,
        );
    }

    /**
     * @return array{apps: array{path: string|null}|null, tasks?: array{worker: string}}
     */
    public function toArray(): array
    {
        $settings = ['apps' => $this->apps?->toArray()];

        if ($this->taskWorker !== null) {
            $settings['tasks'] = ['worker' => $this->taskWorker];
        }

        return $settings;
    }

    public function isEmpty(): bool
    {
        return $this->apps === null && $this->taskWorker === null;
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
