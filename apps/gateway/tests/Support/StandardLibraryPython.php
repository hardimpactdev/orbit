<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Uses the real interpreter without ambient site packages for standard-library-only fixture programs. */
final class StandardLibraryPython
{
    private static ?string $directory = null;

    public static function path(string $path): string
    {
        return dirname(self::executable()).PATH_SEPARATOR.$path;
    }

    public static function executable(): string
    {
        if (self::$directory === null) {
            $python = TestToolchain::require('python3', 'install Python 3');
            $directory = sys_get_temp_dir().'/orbit-stdlib-python-'.bin2hex(random_bytes(12));
            if (! mkdir($directory, 0o700)
                || file_put_contents($directory.'/python3', '#!/bin/sh'."\n".'exec '.escapeshellarg($python).' -S "$@"'."\n") === false
                || ! chmod($directory.'/python3', 0o500)) {
                throw new RuntimeException('Could not prepare the private standard-library Python interpreter.');
            }
            self::$directory = $directory;
        }

        return self::$directory.'/python3';
    }
}
