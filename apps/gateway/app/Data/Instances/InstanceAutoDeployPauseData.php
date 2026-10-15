<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Why automatic deployment of an Instance is paused, and since when. */
#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceAutoDeployPauseData extends Data
{
    public function __construct(
        public string $reason,
        public int $deploymentId,
        public ?string $commit,
        public string $pausedAt,
    ) {}
}
