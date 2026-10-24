<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Requires the service account and its existing private state; never repairs permissions. */
final readonly class NativeGatewayReleaseExecutionOwner implements GatewayReleaseExecutionOwner
{
    /** @param (Closure(): int)|null $effectiveUid */
    public function __construct(private GatewayReleaseLayout $layout, private GatewayReleaseCommands $commands, private ?Closure $effectiveUid = null) {}

    public function assertAllowed(?string $sha = null): void
    {
        $uid = $this->effectiveUid === null ? posix_geteuid() : ($this->effectiveUid)();
        if ($uid <= 0) {
            $this->refuse();
        }
        $home = Config::string('orbit.home');
        $connection = Config::string('database.default');
        $database = $this->database(Config::get('database.connections.'.$connection));
        // The manager may retain a connection opened before configuration changed.
        try {
            $running = DB::connection();
            if ($this->database($running->getConfig()) !== $database || $running->getDriverName() !== 'sqlite') {
                $this->refuse();
            }
            $statement = $running->getPdo()->query('PRAGMA database_list');
            if ($statement === false) {
                $this->refuse();
            }
            $files = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $main = array_values(array_filter($files, static fn (array $file): bool => $file['name'] === 'main'));
            if (count($main) !== 1 || $main[0]['file'] !== $database) {
                $this->refuse();
            }
        } catch (Throwable) {
            $this->refuse();
        }
        $caches = $this->commands->inspectOwner($this->layout, $home, $database, $uid,
            is_string($sha) && GatewayReleaseCommit::isSha($sha) ? $sha : null,
            app()->configurationIsCached() ? app()->getCachedConfigPath() : null);
        foreach ($caches as $cache) {
            $values = require $cache;
            if (! is_array($values) || data_get($values, 'orbit.home') !== $home
                || data_get($values, 'orbit.gateway_checkout') !== $this->layout->applicationPath
                || data_get($values, 'database.default') !== $connection
                || $this->database(data_get($values, 'database.connections.'.$connection)) !== $database) {
                $this->refuse();
            }
        }
    }

    /** Only the single-file SQLite form is supported; no URL, split connections or PDO overrides. */
    private function database(mixed $configuration): string
    {
        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'sqlite'
            || ! in_array($configuration['url'] ?? null, [null, ''], true)
            || array_key_exists('read', $configuration) || array_key_exists('write', $configuration)
            || ! empty($configuration['options'])) {
            $this->refuse();
        }
        $path = $configuration['database'] ?? null;
        // Resolve the connection identity before the runner queries its serving Node.
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_file($path) || realpath($path) !== $path) {
            $this->refuse();
        }

        return $path;
    }

    private function refuse(): never
    {
        throw new GatewayReleaseException('owner', 'gateway.release_owner_invalid', 'Run as the non-root Gateway account with matching owned shared state and cached configuration. No permissions were changed.');
    }
}
