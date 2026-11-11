<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ConnProjectBranchData extends Data
{
    public function __construct(
        public string $name,
        public bool $default,
        public ?ConnBranchPullRequestData $pullRequest,
        public ?ConnBranchInstanceData $instance,
    ) {}
}
