<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Data;

final class ConnBranchInstanceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
