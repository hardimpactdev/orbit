<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use App\Models\Instance;
use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A reachable Project, its visible default placement and advisory clone admission. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnProjectData extends Data
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public ?int $nodeId,
        public ?string $nodeName,
        public ?string $instanceDomainSuffix,
        public bool $cloneable,
        public ?string $cloneableReason,
    ) {}

    public static function fromProject(Project $project, ?Instance $default, ?string $suffix, ?string $reason): self
    {
        return new self(
            id: $project->id,
            slug: $project->slug,
            name: $project->name,
            nodeId: $default?->node->id,
            nodeName: $default?->node->name,
            instanceDomainSuffix: $suffix,
            cloneable: $reason === null,
            cloneableReason: $reason,
        );
    }
}
