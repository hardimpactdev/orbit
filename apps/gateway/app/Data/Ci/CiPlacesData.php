<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** A CI Node's places: `total` is its `ci_places`; a busy runner or a proof copy uses one. */
final class CiPlacesData extends Data
{
    public function __construct(
        public ?int $total,
        public int $runner_jobs,
        public int $proof_copies,
        public ?int $free,
    ) {}
}
