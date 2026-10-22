<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use LogicException;
use Symfony\Component\Process\Process;

/** An immutable migrated database; each concurrency proof owns a separate writable copy. */
final class EnvironmentConcurrencySchemaFixture
{
    private static ?string $directory = null;

    public static function prepare(string $worker): void
    {
        self::$directory = sys_get_temp_dir().'/orbit-env-schema-'.bin2hex(random_bytes(12));
        mkdir(self::$directory, 0700);
        file_put_contents(self::$directory.'/worker.php', $worker);
        new Process([
            PHP_BINARY, self::$directory.'/worker.php', dirname(__DIR__, 2),
            self::$directory.'/gateway.sqlite', self::$directory, 'schema',
        ])->mustRun();
    }

    public static function copyTo(string $database): void
    {
        $directory = self::$directory ?? throw new LogicException('Prepare the concurrency schema in beforeAll.');
        if (! copy($directory.'/gateway.sqlite', $database)) {
            throw new LogicException('Could not copy the concurrency schema.');
        }
        chmod($database, 0600);
    }

    public static function forget(): void
    {
        if (self::$directory !== null) {
            new Filesystem()->deleteDirectory(self::$directory);
            self::$directory = null;
        }
    }
}
