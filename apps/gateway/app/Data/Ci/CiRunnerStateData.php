<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** When the Node's state timer last read its runners, and whether the view has that reading. */
final class CiRunnerStateData extends Data
{
    public function __construct(
        public ?string $observed_at,
        public bool $stale,
        public ?string $error_code,
    ) {}
}
