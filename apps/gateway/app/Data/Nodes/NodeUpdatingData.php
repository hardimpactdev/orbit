<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\NodeUpdateKind;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The update in progress on a Node: a Gateway release, with its record id. */
#[MapOutputName(SnakeCaseMapper::class)]
final class NodeUpdatingData extends Data
{
    public function __construct(
        public NodeUpdateKind $kind,
        public string $since,
        public ?int $release = null,
    ) {}
}
