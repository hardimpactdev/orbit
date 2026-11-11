<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\DefaultInstanceName;
use App\Models\Instance;
use App\Models\Project;

final readonly class ConnInstanceNames
{
    public function isDefault(Project $project, string $name): bool
    {
        return DefaultInstanceName::resolve($project, $name) === DefaultInstanceName::Name;
    }

    /** Any row holds the name, regardless of Node, state or caller. Create alone decides whether that caller can resume it. */
    public function occupied(Project $project, string $name): ?int
    {
        return Instance::query()->where('project_id', $project->id)->where('name', $name)->first(['id'])?->id;
    }
}
