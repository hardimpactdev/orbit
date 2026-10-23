<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\SourceControl\ApplicationDirectory;

final class ProductionApplicationPaths
{
    /** The application directory under a release, such as `/server/web`, or empty when the release is the application. */
    public static function suffix(?string $root): string
    {
        return $root === 'public' || (is_string($root) && str_ends_with($root, '/public'))
            ? ApplicationDirectory::resolve('', $root)
            : '';
    }

    public static function render(string $program, ?string $root): string
    {
        $suffix = self::suffix($root);
        $target = str_repeat('../', 2 + substr_count($suffix, '/')).'.env';

        $assignment = $suffix === '' ? '' : 'application_suffix='.escapeshellarg($suffix)."\n";

        return $assignment.strtr($program, [
            '__APPLICATION_SUFFIX__' => $suffix === '' ? '' : '${application_suffix}',
            '__ENVIRONMENT_TARGET__' => $target,
            '__ENVIRONMENT_PATH__' => $suffix === '' ? '.env' : '"${application_suffix#/}/.env"',
        ]);
    }
}
