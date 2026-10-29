<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyPullRequestData extends Data
{
    public function __construct(
        public string $repository,
        public int $number,
        public string $url,
        public string $head_commit,
        public int $group_id,
        public string $run_id,
    ) {}
}
