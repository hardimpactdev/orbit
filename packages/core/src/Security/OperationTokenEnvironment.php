<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

use InvalidArgumentException;

/**
 * Canonical allowlisted environment for operation-token command context.
 *
 * Gateway minting and CLI verification must filter process/transport environment
 * through this helper so both sides hash the same bound keys.
 */
final class OperationTokenEnvironment
{
    /**
     * Keys that may participate in operation-token command-context binding.
     *
     * @var list<string>
     */
    public const array ALLOWLISTED_KEYS = [
        'APP_KEY',
        'HOME',
        'ORBIT_BIN_PATH',
        'ORBIT_CONFIG_PATH',
        'ORBIT_INSTALL_METADATA_PATH',
        'ORBIT_WG_EASY_DB_PATH',
    ];

    /**
     * @param  array<array-key, mixed>  $environment
     * @return array<string, string>
     */
    public static function allowlisted(array $environment): array
    {
        $normalized = [];

        foreach (self::ALLOWLISTED_KEYS as $key) {
            if (! array_key_exists($key, $environment)) {
                continue;
            }

            if (! is_string($environment[$key])) {
                throw new InvalidArgumentException(
                    'Operation token environment values must be strings.',
                );
            }

            $value = $environment[$key];

            if (str_contains($value, "\0")) {
                throw new InvalidArgumentException(
                    'Operation token environment contains a null byte.',
                );
            }

            if ($value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Collect allowlisted non-empty values from the current process environment.
     *
     * @return array<string, string>
     */
    public static function fromProcess(): array
    {
        $environment = [];

        foreach (self::ALLOWLISTED_KEYS as $key) {
            $value = getenv($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $environment[$key] = $value;
        }

        return self::allowlisted($environment);
    }

    /**
     * Keys force_remote_host must unset so the host CLI only observes HOME.
     *
     * @return list<string>
     */
    public static function forceRemoteHostUnsetKeys(): array
    {
        return array_values(array_filter(
            self::ALLOWLISTED_KEYS,
            static fn (string $key): bool => $key !== 'HOME',
        ));
    }
}
