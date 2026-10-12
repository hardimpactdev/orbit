<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\NodeTaskSettingsData;

final readonly class NodeSettingsNormalizer
{
    public function normalize(?NodeSettingsData $settings): ?NodeSettingsData
    {
        if (! $settings instanceof NodeSettingsData) {
            return null;
        }

        $normalized = new NodeSettingsData(
            apps: $this->nested($settings->appsPath()),
            tasks: $settings->taskWorker() === null ? null : new NodeTaskSettingsData($settings->taskWorker()),
        );

        return $normalized->isEmpty() ? null : $normalized;
    }

    /** @return array<string, mixed>|null */
    public function stored(?NodeSettingsData $settings): ?array
    {
        $normalized = $this->normalize($settings);

        if (! $normalized instanceof NodeSettingsData) {
            return null;
        }

        return array_filter([
            'apps' => $normalized->appsPath() === null ? null : ['path' => $normalized->appsPath()],
            'tasks' => $normalized->taskWorker() === null ? null : ['worker' => $normalized->taskWorker()],
        ], static fn (?array $member): bool => $member !== null);
    }

    public function fromStored(mixed $value): ?NodeSettingsData
    {
        if (! is_array($value)) {
            return null;
        }

        $worker = data_get($value, 'tasks.worker');

        return $this->normalize(new NodeSettingsData(
            apps: $this->nestedFromStored($value['apps'] ?? null),
            tasks: is_string($worker) ? new NodeTaskSettingsData($worker) : null,
        ));
    }

    private function nested(?string $path): ?NodeStorageAppsData
    {
        return $path === null ? null : new NodeStorageAppsData($path);
    }

    private function nestedFromStored(mixed $value): ?NodeStorageAppsData
    {
        $path = $this->pathFromStored($value);

        return $path === null ? null : new NodeStorageAppsData($path);
    }

    private function pathFromStored(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return is_string($path) ? $path : null;
    }
}
