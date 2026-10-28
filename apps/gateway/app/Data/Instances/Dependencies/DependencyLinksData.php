<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyLinksData extends Data
{
    public function __construct(
        public int $group_id,
        public string $run_id,
    ) {}
}
