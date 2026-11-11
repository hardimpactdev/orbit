<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Data;

final class ConnBranchPullRequestData extends Data
{
    public function __construct(
        public int $number,
        public string $title,
        public string $url,
    ) {}
}
