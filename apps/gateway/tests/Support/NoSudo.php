<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

final class NoSudo
{
    private static ?string $directory = null;

    private static ?string $originalPath = null;

    /** @param list<string> $groups */
    public static function start(array $groups): void
    {
        if (in_array('privileged', $groups, true)) {
            return;
        }

        self::$originalPath = (string) getenv('PATH');
        self::$directory = sys_get_temp_dir().'/orbit-no-sudo-'.bin2hex(random_bytes(12));
        if (! mkdir(self::$directory, 0o700)) {
            throw new RuntimeException('Could not create the sudo guard directory.');
        }

        $log = escapeshellarg(self::$directory.'/calls');
        file_put_contents(self::$directory.'/sudo', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> {$log}\nprintf '%s\\n' 'A non-privileged Gateway test called sudo. Fake the boundary or use the privileged group.' >&2\nexit 126\n");
        chmod(self::$directory.'/sudo', 0o700);
        self::setPath(self::path(self::$originalPath));
    }

    public static function path(string $path): string
    {
        return self::$directory === null ? $path : self::$directory.PATH_SEPARATOR.$path;
    }

    public static function finish(): void
    {
        if (self::$directory === null) {
            return;
        }

        $directory = self::$directory;
        self::$directory = null;
        self::setPath(self::$originalPath ?? '');
        self::$originalPath = null;
        $calls = is_file($directory.'/calls') ? file_get_contents($directory.'/calls') : false;
        if ($calls !== false) {
            unlink($directory.'/calls');
        }
        unlink($directory.'/sudo');
        rmdir($directory);

        if ($calls !== false) {
            Assert::fail("A non-privileged Gateway test called sudo:\n{$calls}Fake the process boundary or put the real identity/permission test in the privileged group.");
        }
    }

    private static function setPath(string $path): void
    {
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    }
}
