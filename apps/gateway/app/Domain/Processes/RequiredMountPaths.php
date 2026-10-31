<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use InvalidArgumentException;

final class RequiredMountPaths
{
    /** @return list<string> */
    public static function parse(mixed $paths): array
    {
        if (! is_array($paths) || ! array_is_list($paths) || count($paths) > 64) {
            throw new InvalidArgumentException('Required mount paths must be a list of at most 64 unique absolute paths.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || ! str_starts_with($path, '/') || strlen($path) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || str_contains($path, '//')
                || ($path !== '/' && str_ends_with($path, '/'))
                || array_intersect(explode('/', $path), ['.', '..']) !== []) {
                throw new InvalidArgumentException('Required mount paths must be canonical absolute paths of at most 4096 bytes without control characters.');
            }
        }
        if (count(array_unique($paths, SORT_STRING)) !== count($paths)) {
            throw new InvalidArgumentException('Required mount paths must be unique.');
        }

        return $paths;
    }
}
