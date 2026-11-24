<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The summary a Conn client reads from the Project list to tag a General thread to a Project (projects.md#project-summary).
 * Orbit stores no Project icon or color, so both are always null.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class ProjectSummaryData extends Data
{
    public function __construct(
        public string $repository,
        public ?string $nodeName,
        public ?string $icon,
        public ?string $color,
        public int $instanceCount,
    ) {}

    /** Expects `defaultInstance.node` loaded and `instances_count` counted, as ListProjectsAction does. */
    public static function fromModel(Project $project): self
    {
        return new self(
            repository: $project->repository_identity,
            nodeName: $project->defaultInstance?->node?->name,
            icon: null,
            color: null,
            instanceCount: (int) $project->getAttribute('instances_count'),
        );
    }
}
