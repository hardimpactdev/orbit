<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** Used and total bytes. */
final class CiUsageData extends Data
{
    public function __construct(
        public int $used_bytes,
        public int $total_bytes,
    ) {}
}
