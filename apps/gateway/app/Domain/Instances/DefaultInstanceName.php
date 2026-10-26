<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Project;

/**
 * The reserved name of a Project's default development source. A requested name that equals the
 * Project default branch means this Instance, so development create and registration convert it.
 */
final readonly class DefaultInstanceName
{
    public const string Name = 'default';

    /** Returns `default` when the requested name is the Project default branch, otherwise the requested name. */
    public static function resolve(Project $project, string $requested): string
    {
        return $requested === $project->default_branch ? self::Name : $requested;
    }

    /** Returns the requested name when {@see resolve()} converts it, otherwise null. */
    public static function convertedFrom(Project $project, string $requested): ?string
    {
        return self::resolve($project, $requested) === $requested ? null : $requested;
    }
}
