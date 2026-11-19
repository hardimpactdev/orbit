<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeCiData;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use Spatie\LaravelData\Optional;

final readonly class NodeSettingsNormalizer
{
    public function normalize(?NodeSettingsData $settings): ?NodeSettingsData
    {
        if (! $settings instanceof NodeSettingsData) {
            return null;
        }

        $normalized = new NodeSettingsData(
            apps: $this->nested($settings->appsPath()),
            nested_fleet: $settings->nested_fleet,
            instance_host: $settings->instance_host,
            ci: $settings->ci,
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
            'nested_fleet' => $normalized->nested_fleet ? true : null,
            'instance_host' => $normalized->instance_host ? true : null,
            'ci' => $normalized->ci()?->toArray(),
        ], static fn (array|bool|null $member): bool => $member !== null);
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

        unset($result['nested_fleet']);
        if ($normalized?->nested_fleet) {
            $result['nested_fleet'] = true;
        }

        unset($result['instance_host']);
        if ($normalized?->instance_host) {
            $result['instance_host'] = true;
        }
        unset($result['ci']);
        if ($normalized?->ci() !== null) {
            $result['ci'] = $normalized->ci()->toArray();
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
            nested_fleet: ($value['nested_fleet'] ?? false) === true,
            instance_host: ($value['instance_host'] ?? false) === true,
            ci: is_array($value['ci'] ?? null) ? NodeCiData::from($value['ci']) : new Optional,
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
