<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;

final readonly class NodeSettingsNormalizer
{
    public function normalize(?NodeSettingsData $settings): ?NodeSettingsData
    {
        if (! $settings instanceof NodeSettingsData) {
            return null;
        }

        $normalized = new NodeSettingsData(
            apps: $this->nested($settings->appsPath()),
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
        ], static fn (?array $member): bool => $member !== null);
    }

    /**
     * The stored settings with the member this normalizer manages, `apps.path`, set or cleared.
     * Every other key stays, such as the `t3` connection that T3 Code registration writes.
     *
     * @return array<string, mixed>|null
     */
    public function storedOver(mixed $existing, ?NodeSettingsData $settings): ?array
    {
        $result = is_array($existing) ? $existing : [];
        $normalized = $this->normalize($settings);
        $managed = [
            'apps' => ['path', $normalized?->appsPath()],
        ];

        foreach ($managed as $member => [$key, $set]) {
            $value = is_array($result[$member] ?? null) ? $result[$member] : [];
            unset($value[$key]);
            if ($set !== null) {
                $value[$key] = $set;
            }
            if ($value === []) {
                unset($result[$member]);
            } else {
                $result[$member] = $value;
            }
        }

        return $result === [] ? null : $result;
    }

    public function fromStored(mixed $value): ?NodeSettingsData
    {
        if (! is_array($value)) {
            return null;
        }

        return $this->normalize(new NodeSettingsData(
            apps: $this->nestedFromStored($value['apps'] ?? null),
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
