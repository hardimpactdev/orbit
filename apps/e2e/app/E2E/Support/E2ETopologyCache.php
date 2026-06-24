<?php

declare(strict_types=1);

namespace App\E2E\Support;

final class E2ETopologyCache
{
    /** @var array<string, E2ETopologyLease> */
    private static array $leases = [];

    /** @var (\Closure(E2ETopologyKind, array<string, string>|null, bool, bool): E2ETopologyLease)|null */
    private static ?\Closure $resolver = null;

    private static bool $shutdownRegistered = false;

    public static function enabled(): bool
    {
        $value = getenv('ORBIT_E2E_TOPOLOGY_CACHE');

        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'process'], true);
    }

    /**
     * @param  array<string, string>|null  $sshUsers
     */
    public static function acquire(
        E2ETopologyKind $kind,
        ?array $sshUsers = null,
        bool $withGatewayApi = false,
        bool $sourceMountedCheckout = false,
    ): E2ETopologyHarness {
        self::registerShutdown();

        $factory = E2ETopologyFactory::fromEnvironment();

        if ($sshUsers !== null) {
            $factory = $factory->withSshUsers($sshUsers);
        }

        if ($withGatewayApi) {
            $factory = $factory->withGatewayApi();
        }

        if ($sourceMountedCheckout) {
            $factory = $factory->withSourceMountedCheckout();
        }

        $resolvedKind = $factory->resolveKind($kind);
        $key = self::key($resolvedKind, $sshUsers, $withGatewayApi, $sourceMountedCheckout);

        if (! isset(self::$leases[$key])) {
            self::evictUntilRoomFor($key);

            self::$leases[$key] = self::$resolver !== null
                ? (self::$resolver)($kind, $sshUsers, $withGatewayApi, $sourceMountedCheckout)
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
    private static function key(
        E2ETopologyKind $kind,
        ?array $sshUsers,
        bool $withGatewayApi,
        bool $sourceMountedCheckout,
    ): string {
        $sshUsers ??= [];
        ksort($sshUsers);

        $sourceMode = $sourceMountedCheckout ? 'source-mounted' : 'prepared';

        return (
            $kind->value
            .':'
            .($withGatewayApi ? 'gateway-api' : 'no-gateway-api')
            .":{$sourceMode}:"
            .sha1(json_encode($sshUsers, JSON_THROW_ON_ERROR))
        );
    }

    private static function evictUntilRoomFor(string $key): void
    {
        $limit = self::limit();

        if ($limit === null || isset(self::$leases[$key])) {
            return;
        }

        while (count(self::$leases) >= $limit) {
            $lease = array_shift(self::$leases);

            if ($lease instanceof E2ETopologyLease) {
                $lease->cleanup();
            }
        }
    }

    private static function limit(): ?int
    {
        $value = getenv('ORBIT_E2E_TOPOLOGY_CACHE_LIMIT');

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! ctype_digit($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
