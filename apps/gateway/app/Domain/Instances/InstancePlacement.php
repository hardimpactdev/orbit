<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

/**
 * The one placement rule that every writer of an Instance or its runtime passes before it writes anything
 * (docs/reference/projects.md#nested-fleet-setting). A Project with the nested fleet on has no host yet, so each of its new
 * Instances is refused. Every other Project keeps its placement unchanged.
 */
final readonly class InstancePlacement
{
    public static function assert(Project $project): void
    {
        if ($project->nested_fleet) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Instances of a nested-fleet Project are not available until nested-fleet hosting is built.', 503);
        }
    }
}
