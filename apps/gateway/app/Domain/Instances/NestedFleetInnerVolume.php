<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\InstanceRuntime;

/**
 * The inner volume of a nested-fleet Instance (docs/reference/development-containers.md#the-inner-volume): one custom btrfs volume
 * per Instance on the Node's pool, attached as the disk `inner-pool`, which holds the inner Incus pool. A runtime records its name in
 * `inner_volume`; a runtime without it (Instance 2454 and every clone) keeps the directory pool, and no step makes or checks a volume
 * for it. This is the one place that names the volume's keys: the create writes them and the start guard requires exactly them.
 */
final readonly class NestedFleetInnerVolume
{
    public const string Device = 'inner-pool';

    public const string Path = '/srv/inner-pool';

    public const string Size = '30GiB';

    /** The inner Incus pool on the volume. */
    public const string InnerPool = 'inner';

    public const string InnerPoolSource = self::Path.'/pool';

    /**
     * `security.shifted` keeps a copy from shifting the volume file by file to its new ID map; the mount options let the inner Incus
     * delete its own subvolumes and give freed blocks back to the pool.
     *
     * @var array<string, string>
     */
    public const array Config = [
        'zfs.block_mode' => 'true',
        'block.filesystem' => 'btrfs',
        'block.mount_options' => 'user_subvol_rm_allowed,discard',
        'security.shifted' => 'true',
    ];

    /** The volume's name for a new nested-fleet default's runtime, from its container name `instance-<id>`. */
    public static function name(string $containerName): string
    {
        return 'inner-'.substr($containerName, strlen('instance-'));
    }

    /**
     * The intent's `container.inner_volume`, or null for a runtime without one.
     *
     * @return array{name: string, pool: string, size: string, config: array<string, string>, device: string, path: string,
     *     inner_pool: array{name: string, source: string}}|null
     */
    public static function intent(InstanceRuntime $runtime): ?array
    {
        $name = $runtime->inner_volume;
        if ($name === null) {
            return null;
        }

        return ['name' => $name, 'pool' => InstanceHostIntent::Pool, 'size' => self::Size, 'config' => self::Config,
            'device' => self::Device, 'path' => self::Path, 'inner_pool' => ['name' => self::InnerPool, 'source' => self::InnerPoolSource]];
    }

    /**
     * The `inner-pool` device as the start guard allows it, or none.
     *
     * @return array<string, array<string, string>>
     */
    public static function allowedDevice(InstanceRuntime $runtime): array
    {
        $name = $runtime->inner_volume;

        return $name === null ? [] : [self::Device => ['type' => 'disk', 'pool' => InstanceHostIntent::Pool, 'source' => $name, 'path' => self::Path]];
    }
}
