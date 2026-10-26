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
    public function __construct(private GatewayReleaseLayout $layout, private ?Closure $effectiveUid = null, private ?int $accountUid = null) {}

    public function assertAllowed(?string $sha = null): void
    {
        $uid = $this->effectiveUid === null ? posix_geteuid() : ($this->effectiveUid)();
        $account = $this->accountUid ?? (posix_getpwnam('orbit')['uid'] ?? -1);
        if ($uid <= 0 || $uid !== $account) {
            $this->refuse();
        }
        $home = Config::string('orbit.home');
        $connection = Config::string('database.default');
        $database = $this->database(Config::get('database.connections.'.$connection));
        if (! str_starts_with($home, '/')) {
            $this->refuse();
        }
        foreach ([$home, $this->layout->basePath(), $this->layout->sharedPath(), $this->layout->storagePath(), $this->layout->releasesPath()] as $path) {
            $this->owned($path, $uid);
            if (! is_dir($path)) {
                $this->refuse();
            }
        }
        foreach ([$database, $this->layout->environmentPath()] as $path) {
            $this->owned($path, $uid);
            if (! is_file($path)) {
                $this->refuse();
            }
        }
        foreach ([$home.'/gateway-release.lock', $home.'/gateway-release-step.lock', $home.'/backups', $home.'/recovery', $home.'/gateway-release.paused'] as $path) {
            if (file_exists($path) || is_link($path)) {
                $this->owned($path, $uid);
                if (in_array($path, [$home.'/gateway-release.lock', $home.'/gateway-release-step.lock'], true)
                    && (! is_file($path) || (fileperms($path) & 07777) !== 0600)) {
                    $this->refuse();
                }
            }
        }
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
        $caches = [$this->layout->applicationPath.'/bootstrap/cache/config.php'];
        if (app()->configurationIsCached()) {
            $caches[] = app()->getCachedConfigPath();
        }
        if (is_string($sha) && GatewayReleaseCommit::isSha($sha)) {
            $target = $this->layout->releasePath(substr($sha, 0, 12));
            if (file_exists($target) || is_link($target)) {
                $this->owned($target, $uid);
                if (is_dir($target.'/apps/gateway/bootstrap/cache')) {
                    $this->owned($target.'/apps/gateway/bootstrap/cache', $uid);
                }
                if (file_exists($target.'/apps/gateway/bootstrap/cache/config.php') || file_exists($target.'/REVISION')) {
                    $caches[] = $target.'/apps/gateway/bootstrap/cache/config.php';
                }
            }
        }
        foreach (array_unique($caches) as $cache) {
            $this->owned($cache, $uid);
            if ((fileperms($cache) & 0077) !== 0) {
                $this->refuse();
            }
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
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_file($path) || realpath($path) !== $path) {
            $this->refuse();
        }

        return $path;
    }

    private function owned(string $path, int $uid): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! file_exists($path) || fileowner($path) !== $uid || ! is_readable($path)
            || (fileperms($path) & 0022) !== 0) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        throw new GatewayReleaseException('owner', 'gateway.release_owner_invalid', 'Run as the non-root Gateway account with matching owned shared state and cached configuration. No permissions were changed.');
    }
}
