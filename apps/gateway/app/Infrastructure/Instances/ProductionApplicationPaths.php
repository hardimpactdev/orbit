<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\SourceControl\ApplicationDirectory;

final class ProductionApplicationPaths
{
    /** @param list<string>|null $sharedPaths */
    public static function render(string $program, ?string $root, ?array $sharedPaths = null): string
    {
        $suffix = $root === 'public' || (is_string($root) && str_ends_with($root, '/public'))
            ? ApplicationDirectory::resolve('', $root)
            : '';
        $target = str_repeat('../', 2 + substr_count($suffix, '/')).'.env';

        $assignment = $suffix === '' ? '' : 'application_suffix='.escapeshellarg($suffix)."\n";

        $program = str_replace('__SHARED_PATHS__', $sharedPaths === null ? '' : self::sharedPaths($sharedPaths), $program);

        return $assignment.strtr($program, [
            '__APPLICATION_SUFFIX__' => $suffix === '' ? '' : '${application_suffix}',
            '__ENVIRONMENT_TARGET__' => $target,
            '__ENVIRONMENT_PATH__' => $suffix === '' ? '.env' : '"${application_suffix#/}/.env"',
        ]);
    }

    /** @param list<string> $paths */
    private static function sharedPaths(array $paths): string
    {
        $entries = base64_encode(implode("\n", $paths));

        $library = file_get_contents(resource_path('remote/lib/production-shared-paths.sh'));

        if (! is_string($library) || $library === '') {
            throw new \LogicException('The production shared path library is missing.');
        }

        return $library.'link_shared_paths "$user" "$home" "$release" "$relative_root" "${application_suffix:-}" '.escapeshellarg($entries);
    }
}
