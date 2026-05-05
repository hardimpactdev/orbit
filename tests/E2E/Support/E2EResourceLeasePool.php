<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

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
            directory: self::envString('ORBIT_E2E_LEASE_DIRECTORY', self::defaultDirectoryFor(base_path())),
            waitSeconds: $waitSeconds ?? self::envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
            staleSeconds: $staleSeconds ?? self::envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
        );
    }

    public static function defaultDirectoryFor(string $basePath): string
    {
        $root = self::sharedRepositoryRoot($basePath) ?? rtrim($basePath, '/');

        return $root.'/storage/framework/e2e/leases';
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param  array<string, int>  $hostSlots
     */
    public function acquire(string $backend, array $hostSlots): E2EResourceLease
    {
        if ($hostSlots === []) {
            throw new RuntimeException("No {$backend} E2E slots are configured.");
        }

        $this->ensureDirectory();
        $deadline = microtime(true) + $this->waitSeconds;

        do {
            $this->reclaimStaleLeases($backend, $hostSlots);

            foreach ($hostSlots as $host => $slots) {
                for ($slot = 1; $slot <= $slots; $slot++) {
                    $lease = $this->tryAcquire($backend, $host, $slot);

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
     * @return list<array{host: string, slot: int, leased: bool}>
     */
    public function snapshot(string $backend, array $hostSlots): array
    {
        $this->reclaimStaleLeases($backend, $hostSlots);

        $snapshot = [];

        foreach ($hostSlots as $host => $slots) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $snapshot[] = [
                    'host' => $host,
                    'slot' => $slot,
                    'leased' => is_file($this->path($backend, $host, $slot)),
                ];
            }
        }

        return $snapshot;
    }

    private function tryAcquire(string $backend, string $host, int $slot): ?E2EResourceLease
    {
        $owner = bin2hex(random_bytes(16));
        $path = $this->path($backend, $host, $slot);
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            return null;
        }

        try {
            fwrite($handle, json_encode([
                'backend' => $backend,
                'host' => $host,
                'slot' => $slot,
                'owner' => $owner,
                'pid' => getmypid(),
                'created_at' => time(),
            ], JSON_THROW_ON_ERROR));
        } finally {
            fclose($handle);
        }

        return new E2EResourceLease($backend, $host, $slot, $path, $owner);
    }

    /**
     * @param  array<string, int>  $hostSlots
     */
    private function reclaimStaleLeases(string $backend, array $hostSlots): void
    {
        foreach ($hostSlots as $host => $slots) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $path = $this->path($backend, $host, $slot);

                if (! is_file($path)) {
                    continue;
                }

                $modifiedAt = filemtime($path);

                if ($modifiedAt === false || (time() - $modifiedAt) < $this->staleSeconds) {
                    continue;
                }

                @unlink($path);
            }
        }
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

        $commonGitDirectory = dirname(dirname($gitDirectory));

        return dirname($commonGitDirectory);
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
}
