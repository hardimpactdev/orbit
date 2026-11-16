<?php

declare(strict_types=1);

namespace App\Support;

use Orbit\Sdk\Requests\Nodes\CiPlacesInput;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettings;

final readonly class NodeSettingOptions
{
    /** @var list<string> */
    private const array KNOWN = ['apps.path'];

    /**
     * @return array{ok: true, provided: bool, body: array{apps?: array{path: string}|null}}|array{ok: false, code: string, message: string}
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

            $body['apps'] = $value === '' ? null : ['path' => $value];
        }

        return ['ok' => true, 'provided' => true, 'body' => $body];
    }

    /** @param array{apps?: array{path: string}|null} $body */
    public static function settings(array $body): NodeSettings
    {
        return new NodeSettings(
            apps: array_key_exists('apps', $body) ? self::apps($body['apps']) : null,
        );
    }

    /**
     * Reads the CI options of node:update (docs/reference/node-settings.md#ci-places): `--ci-places` from 0 to 64, one of the
     * proof-only switches, or `--no-ci` alone to clear the setting. `provided` is false when no CI option was given.
     *
     * @return array{ok: true, provided: bool, value: CiPlacesInput|null}|array{ok: false, code: string, message: string}
     */
    public static function parseCi(mixed $places, bool $proofOnly, bool $notProofOnly, bool $clear): array
    {
        if (($clear && (is_string($places) || $proofOnly || $notProofOnly)) || ($proofOnly && $notProofOnly)) {
            return ['ok' => false, 'code' => 'node.ci_option_conflict',
                'message' => 'Use --no-ci alone, and only one of --ci-proof-only or --no-ci-proof-only.'];
        }
        if (is_string($places) && (preg_match('/^\d{1,2}$/', $places) !== 1 || (int) $places > 64)) {
            return ['ok' => false, 'code' => 'node.ci_places_invalid', 'message' => 'CI places must be an integer from 0 to 64.'];
        }
        if ($clear) {
            return ['ok' => true, 'provided' => true, 'value' => null];
        }
        $input = new CiPlacesInput(is_string($places) ? (int) $places : null, $proofOnly ? true : ($notProofOnly ? false : null));

        return ['ok' => true, 'provided' => $input->toArray() !== [], 'value' => $input];
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
