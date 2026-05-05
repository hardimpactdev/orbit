<?php

declare(strict_types=1);

/**
 * @param  list<string>  $additionalKeys
 * @param  array<string, string>  $values
 */
function withE2EEnvironment(array $additionalKeys, array $values, Closure $callback): void
{
    $keys = array_values(array_unique(array_merge([
        'ORBIT_E2E_PROVIDER',
        'ORBIT_E2E_PROVIDERS',
        'ORBIT_E2E_TOPOLOGY_PROVIDER',
        'ORBIT_E2E_TOPOLOGY_PROVIDERS',
        'ORBIT_E2E_INSTANCE_PREFIX',
        'ORBIT_E2E_DOCKER_HOSTS',
        'ORBIT_E2E_DOCKER_HOST_SLOTS',
        'ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST',
    ], $additionalKeys)));

    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if (is_string($value)) {
                putenv("{$key}={$value}");

                continue;
            }

            putenv($key);
        }
    }
}

/**
 * @param  array<string, string>  $values
 */
function withE2EConfigEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([
        'ORBIT_E2E_CPUS',
        'ORBIT_E2E_MEMORY',
        'ORBIT_E2E_TOPOLOGY_CPUS',
        'ORBIT_E2E_TOPOLOGY_MEMORY',
    ], $values, $callback);
}

/**
 * @param  array<string, string>  $values
 */
function withE2EProviderEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([], $values, $callback);
}

/**
 * @param  array<string, string>  $values
 */
function withE2ETopologyEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([
        'ORBIT_E2E_TOPOLOGY_STRATEGY',
        'ORBIT_E2E_INCUS_HOSTS',
        'ORBIT_E2E_HOST',
    ], $values, $callback);
}
