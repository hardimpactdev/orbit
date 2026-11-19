<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Domain\Shared\ResourceOperationException;
use stdClass;

final readonly class NodeSettingsParser
{
    /** @var list<string> */
    private const array TOP_LEVEL_KEYS = ['apps', 'nested_fleet', 'instance_host'];

    /**
     * Only node:update sets a CI Node's `ci`; node:add takes the other members.
     *
     * @var list<string>
     */
    private const array PATCH_KEYS = [...self::TOP_LEVEL_KEYS, 'ci'];

    /** @var list<string> */
    private const array CI_KEYS = ['places', 'proof_only'];

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
        $this->assertClosedKeys($object, self::PATCH_KEYS);

        if ($object === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsPatch(
            hasApps: array_key_exists('apps', $object),
            apps: array_key_exists('apps', $object) ? $this->nestedApps($object['apps']) : null,
            hasNestedFleet: array_key_exists('nested_fleet', $object),
            nestedFleet: $this->nestedFleet(array_key_exists('nested_fleet', $object) ? $object['nested_fleet'] : false),
            hasInstanceHost: array_key_exists('instance_host', $object),
            instanceHost: $this->flag('instance_host', $object),
            hasCi: array_key_exists('ci', $object),
            ci: array_key_exists('ci', $object) ? $this->nestedCi($object['ci']) : null,
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
            nested_fleet: $this->nestedFleet(array_key_exists('nested_fleet', $object) ? $object['nested_fleet'] : false),
            instance_host: $this->flag('instance_host', $object),
        );
    }

    private function nestedFleet(mixed $value): bool
    {
        if (! is_bool($value)) {
            $this->reject('nested_fleet must be a JSON boolean.');
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $object */
    private function flag(string $key, array $object): bool
    {
        $value = $object[$key] ?? false;
        if (! is_bool($value)) {
            $this->reject("{$key} must be a JSON boolean.");
        }

        return $value;
    }

    /** A `ci` patch names at least one member: `places`, an integer from 0 to 64, or `proof_only`, a boolean. */
    private function nestedCi(mixed $value): ?NodeCiPatch
    {
        if ($value === null) {
            return null;
        }
        $object = $this->assertNestedObject($value, self::CI_KEYS);
        $places = $object['places'] ?? null;
        $proofOnly = $object['proof_only'] ?? null;
        match (true) {
            $object === [] => $this->reject('ci must name places or proof_only.'),
            array_key_exists('places', $object) && (! is_int($places) || $places < 0 || $places > 64) => $this->reject('ci.places must be an integer from 0 to 64.'),
            array_key_exists('proof_only', $object) && ! is_bool($proofOnly) => $this->reject('ci.proof_only must be a JSON boolean.'),
            default => null,
        };

        return new NodeCiPatch(is_int($places) ? $places : null, is_bool($proofOnly) ? $proofOnly : null);
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
