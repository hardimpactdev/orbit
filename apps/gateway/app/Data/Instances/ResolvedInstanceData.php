<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class ResolvedInstanceData extends Data
{
    public function __construct(
        public readonly string $domain,
        public readonly int $instance_id,
        public readonly int $project_id,
        public readonly int $node_id,
        public readonly string $environment,
    ) {}
}
