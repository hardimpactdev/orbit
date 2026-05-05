<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class E2ETopologyCache
{
    /** @var array<string, E2ETopologyLease> */
    private static array $leases = [];

    /** @var (\Closure(E2ETopologyKind, array<string, string>|null): E2ETopologyLease)|null */
    private static ?\Closure $resolver = null;

    private static bool $shutdownRegistered = false;

    public static function enabled(): bool
    {
        $value = getenv('ORBIT_E2E_TOPOLOGY_CACHE');

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'yes', 'process'], true);
    }

    /**
     * @param  array<string, string>|null  $sshUsers
     */
    public static function acquire(E2ETopologyKind $kind, ?array $sshUsers = null, bool $withGatewayApi = false): E2ETopologyHarness
    {
        self::registerShutdown();

        $factory = E2ETopologyFactory::fromEnvironment();

        if ($sshUsers !== null) {
            $factory = $factory->withSshUsers($sshUsers);
        }

        if ($withGatewayApi) {
            $factory = $factory->withGatewayApi();
        }

        $resolvedKind = $factory->resolveKind($kind);
        $key = self::key($resolvedKind, $sshUsers, $withGatewayApi);

        if (! isset(self::$leases[$key])) {
            self::$leases[$key] = self::$resolver !== null
                ? (self::$resolver)($kind, $sshUsers)
                : $factory->require($kind);
        }

        return new E2ETopologyHarness(
            lease: self::$leases[$key],
            cleanupOnRelease: false,
        );
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$leases) as $lease) {
            $lease->cleanup();
        }

        self::$leases = [];
    }

    public static function fakeResolver(?callable $resolver): void
    {
        self::$resolver = $resolver !== null ? \Closure::fromCallable($resolver) : null;
    }

    public static function flushForTests(bool $cleanup = true): void
    {
        if ($cleanup) {
            self::cleanup();
        } else {
            self::$leases = [];
        }

        self::$resolver = null;
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function([self::class, 'cleanup']);
    }

    /**
     * @param  array<string, string>|null  $sshUsers
     */
    private static function key(E2ETopologyKind $kind, ?array $sshUsers, bool $withGatewayApi): string
    {
        $sshUsers ??= [];
        ksort($sshUsers);

        return $kind->value.':'.($withGatewayApi ? 'gateway-api' : 'no-gateway-api').':'.sha1(json_encode($sshUsers, JSON_THROW_ON_ERROR));
    }
}
