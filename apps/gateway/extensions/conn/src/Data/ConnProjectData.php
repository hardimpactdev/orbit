<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use App\Models\Instance;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A Project a device can start a thread in, with the Node of the default Instance that its clones copy. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnProjectData extends Data
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public int $nodeId,
        public string $nodeName,
    ) {}

    public static function fromDefault(Instance $default): self
    {
        return new self(
            id: $default->project->id,
            slug: $default->project->slug,
            name: $default->project->name,
            nodeId: $default->node->id,
            nodeName: $default->node->name,
        );
    }
}
