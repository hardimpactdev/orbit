<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class E2EProvisioningBundle
{
    private function __construct(
        private readonly IncusHost $host,
        private readonly string $localPath,
        private readonly string $remotePath,
    ) {}

    public static function stage(IncusProvider $provider): self
    {
        $localPath = self::buildLocal();

        try {
            $remotePath = $provider->host->pushBundle($localPath);

            return new self($provider->host, $localPath, $remotePath);
        } catch (\Throwable $throwable) {
            self::removeLocalPath($localPath);

            throw $throwable;
        }
    }

    public function remotePath(): string
    {
        return $this->remotePath;
    }

    public function cleanup(): void
    {
        $this->host->cleanupBundle($this->remotePath);
        self::removeLocalPath($this->localPath);
    }

    private static function buildLocal(): string
    {
        $bundleDir = sys_get_temp_dir().'/orbit-e2e-bundle-'.bin2hex(random_bytes(6));

        if (! mkdir($bundleDir, 0755, true)) {
            throw new RuntimeException("Could not create local bundle directory: {$bundleDir}");
        }

        try {
            self::buildSourceArchive($bundleDir);
            self::stageScript($bundleDir, 'install-orbit');
            self::stageScript($bundleDir, 'e2e-provision-node');
            self::stageScript($bundleDir, '_e2e-deps.sh');
            self::stageComposerCache($bundleDir);

            return $bundleDir;
        } catch (\Throwable $throwable) {
            self::removeLocalPath($bundleDir);

            throw $throwable;
        }
    }

    private static function buildSourceArchive(string $bundleDir): void
    {
        $archive = "{$bundleDir}/orbit-source.tar.gz";
        $excludeArgs = implode(' ', array_map(
            fn (string $pattern): string => '--exclude='.escapeshellarg($pattern),
            [
                './.git',
                './.env',
                './database/*.sqlite',
                './database/*.sqlite-*',
                './node_modules',
                './storage/framework/cache/data/*',
                './storage/framework/sessions/*',
                './storage/framework/testing/*',
                './storage/framework/views/*',
                './storage/logs/*',
                './vendor',
            ],
        ));

        $result = Process::timeout(300)->run(sprintf(
            'COPYFILE_DISABLE=1 tar %s -czf %s -C %s .',
            $excludeArgs,
            escapeshellarg($archive),
            escapeshellarg(base_path()),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to build source archive: {$result->errorOutput()}");
        }
    }

    private static function stageScript(string $bundleDir, string $name): void
    {
        $source = base_path("bin/{$name}");

        if (! is_file($source)) {
            throw new RuntimeException("Required bin script missing: {$source}");
        }

        $target = "{$bundleDir}/{$name}";

        if (! @copy($source, $target)) {
            throw new RuntimeException("Could not stage {$name} into bundle.");
        }

        @chmod($target, 0755);
    }

    private static function stageComposerCache(string $bundleDir): void
    {
        $home = (string) (getenv('HOME') ?: '');
        $composerCache = $home !== '' ? "{$home}/.cache/orbit-e2e/composer" : null;

        if ($composerCache === null || ! is_dir($composerCache)) {
            return;
        }

        $target = "{$bundleDir}/composer-cache";

        if (! mkdir($target, 0755, true)) {
            throw new RuntimeException("Could not create composer cache target: {$target}");
        }

        $result = Process::timeout(120)->run(sprintf(
            'cp -R %s %s',
            escapeshellarg(rtrim($composerCache, '/').'/.'),
            escapeshellarg($target),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Could not copy composer cache: {$result->errorOutput()}");
        }
    }

    private static function removeLocalPath(string $path): void
    {
        if (is_dir($path)) {
            Process::timeout(120)->run('rm -rf '.escapeshellarg($path));
        }
    }
}
