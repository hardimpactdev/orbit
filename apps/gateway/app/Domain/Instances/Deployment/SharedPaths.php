<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Instance;
use InvalidArgumentException;

final class SharedPaths
{
    /** @return list<string> */
    public static function forInstance(Instance $instance): array
    {
        if (! $instance->usesProductionReleaseLayout()) {
            return [];
        }

        $instance->loadMissing('project');
        $paths = $instance->shared_paths;

        if ($paths === null) {
            $root = $instance->root ?? $instance->project->root;
            $directory = $root === 'public' || (is_string($root) && str_ends_with($root, '/public'))
                ? ApplicationDirectory::resolve('', $root)
                : '';
            $paths = $instance->project->type === ProjectType::LaravelApp ? [ltrim($directory.'/storage', '/')] : [];
        }

        return self::validate($paths);
    }

    /** @return list<string> */
    public static function validate(mixed $paths): array
    {
        if (! is_array($paths) || ! array_is_list($paths) || count($paths) > 32) {
            throw new InvalidArgumentException('Shared paths must be a list of at most 32 directory paths.');
        }

        $validated = [];

        foreach ($paths as $path) {
            if (! is_string($path) || strlen($path) > 255
                || preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9._-]+)*\z~', $path) !== 1
                || preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $path) === 1
                || in_array(explode('/', $path)[0], ['releases', 'current', 'env', 'database.sqlite'], true)) {
                throw new InvalidArgumentException('A shared path is invalid or reserved by Orbit.');
            }

            foreach ($validated as $other) {
                if ($path === $other || str_starts_with($path, $other.'/') || str_starts_with($other, $path.'/')) {
                    throw new InvalidArgumentException('Shared paths must be distinct and must not overlap.');
                }
            }

            $validated[] = $path;
        }

        return $validated;
    }
}
