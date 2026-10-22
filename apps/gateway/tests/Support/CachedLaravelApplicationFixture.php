<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Reuses native initial caches, never a writable application or its refreshed configuration. */
final class CachedLaravelApplicationFixture
{
    /** @var array<string, array{application: string, files: array<string, array{source: string, mode: int}>}> */
    private static array $seeds = [];

    /** @return array<string, array{string}> */
    public static function cachedValues(): array
    {
        return [
            'scalar' => ['null'],
            'autoloaded enum' => ['Symfony\\Component\\String\\TruncateMode::WordBefore'],
            'autoloaded class' => ['new Carbon\\CarbonImmutable("2026-01-01T00:00:00+00:00")'],
        ];
    }

    public static function prepare(): void
    {
        if (self::$seeds !== []) {
            return;
        }

        $seeds = [];
        $files = new Filesystem;
        foreach (self::cachedValues() as [$cachedValue]) {
            $directory = sys_get_temp_dir().'/orbit-laravel-cache-seed-'.bin2hex(random_bytes(8));
            try {
                $application = self::application($directory, $cachedValue, 'vendor');
                new Process([PHP_BINARY, 'artisan', 'config:cache'], $application, [
                    'QUEUE_CONNECTION' => false, 'APP_CONFIG_CACHE' => false,
                ])->mustRun();
                $cacheFiles = [];
                foreach ($files->files($application.'/bootstrap/cache') as $cacheFile) {
                    $cacheFiles[$cacheFile->getFilename()] = [
                        'source' => $files->get($cacheFile->getPathname()),
                        'mode' => $cacheFile->getPerms() & 0o777,
                    ];
                }
                $seeds[$cachedValue] = ['application' => $application, 'files' => $cacheFiles];
            } finally {
                $files->deleteDirectory($directory);
            }
        }
        self::$seeds = $seeds;
    }

    public static function install(string $directory, string $cachedValue, string $vendorDirectory): string
    {
        $seed = self::$seeds[$cachedValue]
            ?? throw new RuntimeException('Prepare the native Laravel configuration fixture in beforeAll.');
        $application = self::application($directory, $cachedValue, $vendorDirectory);
        $originalPath = substr(var_export($seed['application'], true), 1, -1);
        $privatePath = substr(var_export($application, true), 1, -1);
        $files = new Filesystem;
        foreach ($seed['files'] as $name => $cacheFile) {
            $path = $application.'/bootstrap/cache/'.$name;
            $files->put($path, str_replace($originalPath, $privatePath, $cacheFile['source']));
            if (! chmod($path, $cacheFile['mode'])) {
                throw new RuntimeException('Could not restore the native Laravel cache permissions.');
            }
        }

        return $application;
    }

    private static function application(string $directory, string $cachedValue, string $vendorDirectory): string
    {
        $application = $directory.'/release/apps/site';
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory, 0700);
        foreach (['bootstrap/cache', 'config', 'storage/logs', 'storage/framework/views', $vendorDirectory] as $path) {
            $files->ensureDirectoryExists($application.'/'.$path);
        }
        $files->ensureDirectoryExists($directory.'/bin');
        symlink($directory.'/release', $directory.'/current');
        symlink($directory.'/.env', $application.'/.env');
        symlink(PHP_BINARY, $directory.'/bin/php8.5');
        $files->put($directory.'/bin/sudo', "#!/bin/bash\n[[ \$1 == -u && \$2 == production-user && \$3 == -H ]] || exit 99\nshift 3\nexec \"\$@\"\n");
        chmod($directory.'/bin/sudo', 0700);
        $files->put($directory.'/.env', "QUEUE_CONNECTION=database\n");
        $files->put($application.'/bootstrap/providers.php', '<?php return [];');
        $files->put($application.'/bootstrap/app.php', '<?php return Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->create();');
        $files->put($application.'/'.$vendorDirectory.'/autoload.php', '<?php return require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';');
        $files->put($application.'/artisan', '<?php require __DIR__.'.var_export('/'.$vendorDirectory.'/autoload.php', true).'; $app = require __DIR__."/bootstrap/app.php"; exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));');
        $files->put($application.'/composer.json', json_encode([
            'name' => 'orbit/env-refresh-fixture', 'config' => ['vendor-dir' => $vendorDirectory],
        ], JSON_THROW_ON_ERROR));
        $files->put($application.'/config/queue.php', "<?php return ['default' => env('QUEUE_CONNECTION', 'database'), 'typed' => ".$cachedValue.'];');

        return $application;
    }
}
