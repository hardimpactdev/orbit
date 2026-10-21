<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ToolingBaselineFixture
{
    /** Copy the tooling files of every project from this repository into a fresh fixture root. */
    public static function create(): string
    {
        $repository = dirname(__DIR__, 4);
        $root = sys_get_temp_dir().'/tooling-baseline-'.bin2hex(random_bytes(6));
        foreach (['apps/cli', 'apps/gateway', 'apps/docs', 'apps/e2e', 'packages/php-sdk'] as $project) {
            mkdir($root.'/'.$project, 0777, true);
            foreach (['composer.json', 'pint.json', 'rector.php', 'phpstan.neon', 'phpunit.xml', 'phpunit.xml.dist'] as $file) {
                if (is_file($repository.'/'.$project.'/'.$file)) {
                    copy($repository.'/'.$project.'/'.$file, $root.'/'.$project.'/'.$file);
                }
            }
        }

        return $root;
    }

    public static function remove(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
