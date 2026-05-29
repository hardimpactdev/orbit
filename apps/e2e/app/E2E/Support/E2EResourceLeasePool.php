<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2EResourceLeasePool
{
    public function __construct(
        private string $directory,
        private int $waitSeconds,
        private int $staleSeconds,
    ) {}

    public static function fromEnvironment(?int $waitSeconds = null, ?int $staleSeconds = null): self
    {
        return new self(
            directory: self::envString('ORBIT_E2E_LEASE_DIRECTORY', self::defaultDirectoryFor(self::currentBasePath())),
            waitSeconds: $waitSeconds ?? self::envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
            staleSeconds: $staleSeconds ?? self::envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
        );
    }

    public static function defaultDirectoryFor(string $basePath): string
    {
        $root = self::repositoryRoot($basePath);

        if ($root !== null) {
            return $root.'/apps/gateway/storage/framework/e2e/leases';
        }

        return rtrim($basePath, '/').'/storage/framework/e2e/leases';
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param  array<string, int>  $hostSlots
     * @param  list<string>  $exclusiveHosts
     */
    public function acquire(string $backend, array $hostSlots, array $exclusiveHosts = []): E2EResourceLease
    {
        if ($hostSlots === []) {
            throw new RuntimeException("No {$backend} E2E slots are configured.");
        }

        $this->ensureDirectory();
        $deadline = microtime(true) + $this->waitSeconds;
        $exclusiveHostLookup = $this->exclusiveHostLookup($exclusiveHosts);

        do {
            $this->reclaimStaleLeases($backend, $hostSlots, $exclusiveHostLookup);

            foreach ($hostSlots as $host => $slots) {
                for ($slot = 1; $slot <= $slots; $slot++) {
                    $lease = $this->tryAcquire($backend, $host, $slot, $exclusiveHostLookup);

                    if ($lease !== null) {
                        return $lease;
                    }
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(200_000);
        } while (true);

        throw new RuntimeException("No {$backend} E2E slot became available within {$this->waitSeconds} seconds.");
    }

    /**
     * @param  array<string, int>  $hostSlots
     * @param  list<string>  $exclusiveHosts
     */
    public function acquireWeighted(string $backend, array $hostSlots, int $slots, array $exclusiveHosts = []): E2EResourceLeaseSet
    {
        if ($slots < 1) {
            throw new RuntimeException("A {$backend} E2E lease must request at least one slot.");
        }

        if ($hostSlots === []) {
            throw new RuntimeException("No {$backend} E2E slots are configured.");
        }

        $this->ensureDirectory();
        $deadline = microtime(true) + $this->waitSeconds;
        $exclusiveHostLookup = $this->exclusiveHostLookup($exclusiveHosts);

        do {
            $this->reclaimStaleLeases($backend, $hostSlots, $exclusiveHostLookup);

            foreach ($hostSlots as $host => $availableSlots) {
                if ($availableSlots < $slots) {
                    continue;
                }

                $leases = $this->tryAcquireWeighted($backend, $host, $availableSlots, $slots, $exclusiveHostLookup);

                if ($leases !== null) {
                    return new E2EResourceLeaseSet($leases);
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(200_000);
        } while (true);

        throw new RuntimeException("No {$backend} E2E capacity for {$slots} slots became available within {$this->waitSeconds} seconds.");
    }

    /**
     * @param  array<string, int>  $hostSlots
     * @param  list<string>  $exclusiveHosts
     * @return list<array{host: string, slot: int, leased: bool}>
     */
    public function snapshot(string $backend, array $hostSlots, array $exclusiveHosts = []): array
    {
        $exclusiveHostLookup = $this->exclusiveHostLookup($exclusiveHosts);

        $this->reclaimStaleLeases($backend, $hostSlots, $exclusiveHostLookup);

        $snapshot = [];

        foreach ($hostSlots as $host => $slots) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $snapshot[] = [
                    'host' => $host,
                    'slot' => $slot,
                    'leased' => is_file($this->path($backend, $host, $slot))
                        || ($this->isExclusiveHost($host, $exclusiveHostLookup) && $this->hasConflictingHostLease($backend, $host)),
                ];
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, true>  $exclusiveHostLookup
     */
    private function tryAcquire(string $backend, string $host, int $slot, array $exclusiveHostLookup): ?E2EResourceLease
    {
        if (! $this->isExclusiveHost($host, $exclusiveHostLookup)) {
            return $this->tryAcquireSlot($backend, $host, $slot);
        }

        $mutexOwner = bin2hex(random_bytes(16));
        $mutexPath = $this->hostMutexPath($host);
        $mutexHandle = @fopen($mutexPath, 'x');

        if ($mutexHandle === false) {
            return null;
        }

        try {
            $this->writeLeasePayload($mutexHandle, [
                'backend' => '__host_mutex',
                'host' => $host,
                'slot' => 0,
                'owner' => $mutexOwner,
                'pid' => getmypid(),
                'created_at' => time(),
            ]);

            if ($this->hasConflictingHostLease($backend, $host)) {
                return null;
            }

            return $this->tryAcquireSlot($backend, $host, $slot);
        } finally {
            fclose($mutexHandle);
            $this->releaseOwnedPath($mutexPath, $mutexOwner);
        }
    }

    private function tryAcquireSlot(string $backend, string $host, int $slot): ?E2EResourceLease
    {
        $owner = bin2hex(random_bytes(16));
        $path = $this->path($backend, $host, $slot);
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            return null;
        }

        try {
            $this->writeLeasePayload($handle, [
                'backend' => $backend,
                'host' => $host,
                'slot' => $slot,
                'owner' => $owner,
                'pid' => getmypid(),
                'created_at' => time(),
            ]);
        } finally {
            fclose($handle);
        }

        return new E2EResourceLease($backend, $host, $slot, $path, $owner);
    }

    /**
     * @param  array<string, true>  $exclusiveHostLookup
     * @return non-empty-list<E2EResourceLease>|null
     */
    private function tryAcquireWeighted(string $backend, string $host, int $availableSlots, int $requestedSlots, array $exclusiveHostLookup): ?array
    {
        if (! $this->isExclusiveHost($host, $exclusiveHostLookup)) {
            return $this->tryAcquireSlotSet($backend, $host, $availableSlots, $requestedSlots);
        }

        $mutexOwner = bin2hex(random_bytes(16));
        $mutexPath = $this->hostMutexPath($host);
        $mutexHandle = @fopen($mutexPath, 'x');

        if ($mutexHandle === false) {
            return null;
        }

        try {
            $this->writeLeasePayload($mutexHandle, [
                'backend' => '__host_mutex',
                'host' => $host,
                'slot' => 0,
                'owner' => $mutexOwner,
                'pid' => getmypid(),
                'created_at' => time(),
            ]);

            if ($this->hasConflictingHostLease($backend, $host)) {
                return null;
            }

            return $this->tryAcquireSlotSet($backend, $host, $availableSlots, $requestedSlots);
        } finally {
            fclose($mutexHandle);
            $this->releaseOwnedPath($mutexPath, $mutexOwner);
        }
    }

    /**
     * @return non-empty-list<E2EResourceLease>|null
     */
    private function tryAcquireSlotSet(string $backend, string $host, int $availableSlots, int $requestedSlots): ?array
    {
        $leases = [];

        for ($slot = 1; $slot <= $availableSlots && count($leases) < $requestedSlots; $slot++) {
            $lease = $this->tryAcquireSlot($backend, $host, $slot);

            if ($lease === null) {
                continue;
            }

            $leases[] = $lease;
        }

        if (count($leases) === $requestedSlots) {
            return $leases;
        }

        foreach ($leases as $lease) {
            $lease->release();
        }

        return null;
    }

    /**
     * @param  array<string, int>  $hostSlots
     * @param  array<string, true>  $exclusiveHostLookup
     */
    private function reclaimStaleLeases(string $backend, array $hostSlots, array $exclusiveHostLookup = []): void
    {
        foreach ($hostSlots as $host => $slots) {
            if ($this->isExclusiveHost($host, $exclusiveHostLookup)) {
                $this->reclaimStaleLeasePath($this->hostMutexPath($host));
            }

            for ($slot = 1; $slot <= $slots; $slot++) {
                $this->reclaimStaleLeasePath($this->path($backend, $host, $slot));
            }
        }
    }

    private function hasConflictingHostLease(string $backend, string $host): bool
    {
        foreach ($this->leaseFiles() as $path) {
            $payload = $this->readLeasePayload($path);

            if (! is_array($payload)) {
                continue;
            }

            if (($payload['backend'] ?? null) === '__host_mutex') {
                continue;
            }

            if (($payload['host'] ?? null) !== $host) {
                continue;
            }

            if (($payload['backend'] ?? null) === $backend) {
                continue;
            }

            if ($this->reclaimStaleLeasePath($path)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function leaseFiles(): array
    {
        $files = glob("{$this->directory}/*.lease");

        return $files === false ? [] : $files;
    }

    private function reclaimStaleLeasePath(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        if ($this->reclaimDeadProcessLeasePath($path)) {
            return true;
        }

        $modifiedAt = @filemtime($path);

        if ($modifiedAt === false || (time() - $modifiedAt) < $this->staleSeconds) {
            return false;
        }

        @unlink($path);

        return true;
    }

    private function reclaimDeadProcessLeasePath(string $path): bool
    {
        if (! function_exists('posix_kill')) {
            return false;
        }

        $payload = $this->readLeasePayload($path);

        if (! is_array($payload)) {
            return false;
        }

        $pid = $payload['pid'] ?? null;

        if (! is_int($pid) || $pid < 1) {
            return false;
        }

        if (@posix_kill($pid, 0)) {
            return false;
        }

        if (posix_get_last_error() === 1) {
            return false;
        }

        @unlink($path);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readLeasePayload(string $path): ?array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $payload = json_decode($contents, true);

        return is_array($payload) ? $payload : null;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        mkdir($this->directory, 0777, true);
    }

    private function path(string $backend, string $host, int $slot): string
    {
        return $this->directory.'/'.implode('-', [
            $this->sanitize($backend),
            $this->sanitize($host),
            $slot,
        ]).'.lease';
    }

    private function hostMutexPath(string $host): string
    {
        return $this->directory.'/'.implode('-', [
            '__host_mutex',
            $this->sanitize($host),
        ]).'.lease';
    }

    /**
     * @param  resource  $handle
     * @param  array{backend: string, host: string, slot: int, owner: string, pid: int|false, created_at: int}  $payload
     */
    private function writeLeasePayload($handle, array $payload): void
    {
        fwrite($handle, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function releaseOwnedPath(string $path, string $owner): void
    {
        if (! is_file($path)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (is_array($payload) && ($payload['owner'] ?? null) !== $owner) {
            return;
        }

        @unlink($path);
    }

    /**
     * @param  list<string>  $exclusiveHosts
     * @return array<string, true>
     */
    private function exclusiveHostLookup(array $exclusiveHosts): array
    {
        $lookup = [];

        foreach ($exclusiveHosts as $host) {
            $host = strtolower(trim($host));

            if ($host === '') {
                continue;
            }

            $lookup[$host] = true;
        }

        return $lookup;
    }

    /**
     * @param  array<string, true>  $exclusiveHostLookup
     */
    private function isExclusiveHost(string $host, array $exclusiveHostLookup): bool
    {
        return isset($exclusiveHostLookup[strtolower($host)]);
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? $value;
    }

    private static function sharedRepositoryRoot(string $basePath): ?string
    {
        $basePath = rtrim($basePath, '/');
        $gitPath = "{$basePath}/.git";

        if (is_dir($gitPath)) {
            return $basePath;
        }

        if (! is_file($gitPath)) {
            return null;
        }

        $contents = trim((string) file_get_contents($gitPath));

        if (! preg_match('/^gitdir:\s*(?<gitdir>.+)$/', $contents, $matches)) {
            return null;
        }

        $gitDirectory = self::normalizePath(
            str_starts_with($matches['gitdir'], '/')
                ? $matches['gitdir']
                : "{$basePath}/{$matches['gitdir']}",
        );

        if (basename(dirname($gitDirectory)) !== 'worktrees') {
            return null;
        }

        $commonGitDirectory = dirname($gitDirectory, 2);

        return dirname($commonGitDirectory);
    }

    private static function repositoryRoot(string $basePath): ?string
    {
        $basePath = rtrim($basePath, '/');
        $path = $basePath;

        while ($path !== '' && $path !== '/') {
            if (basename($path) === 'gateway' && basename(dirname($path)) === 'apps') {
                return dirname($path, 2);
            }

            if (is_dir("{$path}/.git")) {
                return $path;
            }

            if (is_file("{$path}/.git")) {
                return self::sharedRepositoryRoot($path) ?? $path;
            }

            $parent = dirname($path);

            if ($parent === $path) {
                break;
            }

            $path = $parent;
        }

        return null;
    }

    private static function normalizePath(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);

                continue;
            }

            $normalized[] = $segment;
        }

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $normalized);
    }

    private static function envString(string $key, string $default): string
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    private static function envInt(string $key, int $default): int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private static function currentBasePath(): string
    {
        if (function_exists('base_path')) {
            return base_path();
        }

        return getcwd() ?: __DIR__;
    }
}
