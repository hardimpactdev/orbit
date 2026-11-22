<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Orbit\Extensions\Conn\Models\ConnPushDevice;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A registered iOS push device for the calling Conn Node. The APNs device token is never returned. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnPushDeviceData extends Data
{
    /** @param array{approval: bool, input: bool, turn_finished: bool, turn_failed: bool, hide_titles: bool} $preferences */
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $deviceId,
        public string $apnsEnvironment,
        public string $bundleId,
        public string $appVersion,
        public string $iosVersion,
        public string $locale,
        public array $preferences,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(ConnPushDevice $device): self
    {
        $preferences = $device->preferences;

        return new self(
            id: $device->id,
            nodeId: $device->node_id,
            deviceId: $device->device_id,
            apnsEnvironment: $device->apns_environment,
            bundleId: $device->bundle_id,
            appVersion: $device->app_version,
            iosVersion: $device->ios_version,
            locale: $device->locale,
            preferences: [
                'approval' => (bool) $preferences['approval'],
                'input' => (bool) $preferences['input'],
                'turn_finished' => (bool) $preferences['turn_finished'],
                'turn_failed' => (bool) $preferences['turn_failed'],
                'hide_titles' => (bool) $preferences['hide_titles'],
            ],
            createdAt: $device->created_at->toIso8601String(),
            updatedAt: $device->updated_at->toIso8601String(),
        );
    }
}
