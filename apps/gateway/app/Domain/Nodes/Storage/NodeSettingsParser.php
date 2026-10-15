<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use stdClass;

final readonly class NodeSettingsParser
{
    /** @var list<string> */
    private const array TOP_LEVEL_KEYS = ['apps', 'task_vms'];

    /** @var list<string> */
    private const array NESTED_KEYS = ['path'];

    public function parseComplete(mixed $value): ?NodeSettingsData
    {
        if ($value === null) {
            return null;
        }

        return $this->parseObject($value, requiredMember: false);
    }

    public function parsePatch(mixed $value): NodeSettingsPatch
    {
        $object = $this->jsonObject($value, 'The settings object is invalid.');
        $this->assertClosedKeys($object, self::TOP_LEVEL_KEYS);

        if ($object === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsPatch(
            hasApps: array_key_exists('apps', $object),
            apps: array_key_exists('apps', $object) ? $this->nestedApps($object['apps']) : null,
            hasTaskVms: array_key_exists('task_vms', $object),
            taskVms: array_key_exists('task_vms', $object) ? $this->nestedTaskVms($object['task_vms']) : null,
        );
    }

    private function parseObject(mixed $value, bool $requiredMember): NodeSettingsData
    {
        $object = $this->jsonObject($value, 'The settings object is invalid.');
        $this->assertClosedKeys($object, self::TOP_LEVEL_KEYS);

        if ($requiredMember && $object === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsData(
            apps: array_key_exists('apps', $object) ? $this->nestedApps($object['apps']) : null,
            task_vms: array_key_exists('task_vms', $object) ? $this->nestedTaskVms($object['task_vms']) : null,
        );
    }

    private function nestedTaskVms(mixed $value): ?NodeTaskVmsData
    {
        if ($value === null) {
            return null;
        }
        $object = $this->jsonObject($value, 'task_vms must be an object or null.');
        if (array_key_exists('node_id', $object)) {
            $this->reject('task_vms must not contain node_id.');
        }
        try {
            return NodeTaskVmsData::validated($object, Ipv4Subnet::from(resolve(VpnSettings::class)->subnet()));
        } catch (TaskVmException $exception) {
            $this->reject(str_replace('incus.hosts.0', 'task_vms', $exception->getMessage()));
        }
    }

    private function nestedApps(mixed $value): ?NodeStorageAppsData
    {
        if ($value === null) {
            return null;
        }

        $value = $this->assertNestedObject($value, self::NESTED_KEYS);
        $path = $value['path'] ?? null;

        if ($path !== null && ! is_string($path)) {
            $this->reject('The apps path must be a string or null.');
        }

        if ($path === '') {
            throw new ResourceOperationException(
                errorCode: 'node.settings_path_invalid',
                message: 'The apps storage path is not a normalized absolute path.',
            );
        }

        return new NodeStorageAppsData(is_string($path) ? $path : null);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function assertClosedKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, strict: true)) {
                $this->reject('The settings object contains unsupported keys.');
            }
        }
    }

    /**
     * @param  list<string>  $allowed
     * @return array<array-key, mixed>
     */
    private function assertNestedObject(mixed $value, array $allowed): array
    {
        $object = $this->jsonObject($value, 'Nested settings must be an object or null.');
        $this->assertClosedKeys($object, $allowed);

        return $object;
    }

    /** @return array<array-key, mixed> */
    private function jsonObject(mixed $value, string $message): array
    {
        if (! $value instanceof stdClass) {
            $this->reject($message);
        }

        return get_object_vars($value);
    }

    private function reject(string $message): never
    {
        throw new ResourceOperationException(
            errorCode: 'node.settings_invalid',
            message: $message,
        );
    }
}
