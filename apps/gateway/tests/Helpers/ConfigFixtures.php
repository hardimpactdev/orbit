<?php

declare(strict_types=1);

/**
 * Loads a config file with the given environment keys unset, then restores them.
 *
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function config_without_env(string $file, array $keys): array
{
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        /** @var array<string, mixed> */
        return require config_path($file);
    } finally {
        foreach ($saved as $key => [$env, $server, $process]) {
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($process !== false) {
                putenv("{$key}={$process}");
            }
        }
    }
}

/**
 * The `task_vms` config with its defaults from `config/task_vms.php` and no `ORBIT_TASK_VMS_*` value set, with
 * `$overrides` merged in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function task_vm_config(array $overrides = []): array
{
    $keys = array_map(static fn (string $key): string => "ORBIT_TASK_VMS_{$key}", [
        'ENABLED', 'DEV_CLUSTER_ID', 'WIREGUARD_RANGE', 'MODEL_PROXY_ORIGIN', 'PARK_AFTER_MINUTES', 'MEDIUM_PROJECTS', 'TOPOLOGY_PATHS',
        'PI_ARTIFACT_PATH', 'PI_ARTIFACT_SHA256', 'PI_MODELS', 'INCUS_HOSTS', 'UPCLOUD_ENABLED', 'UPCLOUD_PROJECTS', 'UPCLOUD_TOKEN_FILE',
        'UPCLOUD_MAX_VMS', 'UPCLOUD_ZONE', 'UPCLOUD_PLAN', 'UPCLOUD_DISK_GB', 'UPCLOUD_SOURCE_TEMPLATE',
    ]);

    return array_replace_recursive(config_without_env('task_vms.php', $keys), $overrides);
}
