<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Orbit\Sdk\Responses\Nodes\TaskVmsSettings;
use stdClass;

final readonly class NodeSettingOptions
{
    /** @var list<string> */
    private const array KNOWN = ['apps.path', 'task_vms'];

    /**
     * @return array{ok: true, provided: bool, body: array{apps?: array{path: string}|null, task_vms?: array<string, mixed>|null}}|array{ok: false, code: string, message: string}
     */
    public static function parse(mixed $options): array
    {
        if (! is_array($options) || $options === []) {
            return ['ok' => true, 'provided' => false, 'body' => []];
        }

        $body = [];
        $seen = [];

        foreach ($options as $option) {
            if (! is_string($option)) {
                return self::invalid();
            }

            $separator = strpos(haystack: $option, needle: ':');

            if ($separator === false || $separator === 0) {
                return self::invalid();
            }

            $key = substr(string: $option, offset: 0, length: $separator);
            $value = substr(string: $option, offset: $separator + 1);

            if (! in_array($key, self::KNOWN, strict: true)) {
                return [
                    'ok' => false,
                    'code' => 'node.setting_unknown',
                    'message' => "Unknown setting [{$key}].",
                ];
            }

            if (array_key_exists($key, $seen)) {
                return [
                    'ok' => false,
                    'code' => 'node.setting_duplicate',
                    'message' => "Setting [{$key}] was supplied more than once.",
                ];
            }

            $seen[$key] = true;

            if ($key === 'task_vms') {
                if ($value === '' || $value === 'null') {
                    $body['task_vms'] = null;

                    continue;
                }
                try {
                    $decoded = json_decode($value, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    return self::invalidTaskVms();
                }
                if (! $decoded instanceof stdClass || ! self::validTaskVms(get_object_vars($decoded))) {
                    return self::invalidTaskVms();
                }
                $body['task_vms'] = get_object_vars($decoded);
            } else {
                $body['apps'] = $value === '' ? null : ['path' => $value];
            }
        }

        return ['ok' => true, 'provided' => true, 'body' => $body];
    }

    /** @param array{apps?: array{path: string}|null, task_vms?: array<string, mixed>|null} $body */
    public static function settings(array $body): NodeSettings
    {
        return new NodeSettings(
            apps: array_key_exists('apps', $body) ? self::apps($body['apps']) : null,
            task_vms: self::taskVms($body['task_vms'] ?? null),
        );
    }

    public static function taskVms(mixed $value): ?TaskVmsSettings
    {
        return is_array($value) ? TaskVmsSettings::fromGatewayData($value) : null;
    }

    /** @param array<string, mixed> $value */
    private static function validTaskVms(array $value): bool
    {
        $strings = ['cidr', 'project', 'network', 'pool', 'image_alias', 'memory', 'disk'];
        $integers = ['max_vms', 'cpus', 'warm_vms'];
        if (! is_string($value['cidr'] ?? null) || ! is_int($value['max_vms'] ?? null)) {
            return false;
        }
        foreach ($value as $key => $item) {
            $valid = match (true) {
                in_array($key, $strings, true) => is_string($item),
                in_array($key, $integers, true) => is_int($item),
                $key === 'zfs_dataset' => $item === null || is_string($item),
                $key === 'operator_owned' => is_array($item) && array_is_list($item) && array_all($item, fn (mixed $resource): bool => is_string($resource)),
                default => false,
            };
            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    /** @return array{ok: false, code: string, message: string} */
    private static function invalidTaskVms(): array
    {
        return ['ok' => false, 'code' => 'node.setting_invalid', 'message' => 'task_vms must be a typed JSON host object or null.'];
    }

    public static function apps(mixed $value): ?AppsSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return new AppsSettings(path: is_string($path) ? $path : null);
    }

    /** @return array{ok: false, code: string, message: string} */
    private static function invalid(): array
    {
        return [
            'ok' => false,
            'code' => 'node.setting_invalid',
            'message' => 'Each --setting option must be <setting-path>:<value>.',
        ];
    }
}
