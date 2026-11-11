<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class DevelopmentDeployRetriesData extends Data
{
    public function __construct(
        public int $manual_after,
        public int $observed_through,
        /** @var list<int> */
        public array $unsettled,
        /** @var list<array<string, mixed>> */
        public array $commits,
    ) {}
}
