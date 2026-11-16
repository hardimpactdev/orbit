<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** A CI Node's places: `total` and `proof_only` are its `ci` setting; a busy runner or a proof copy uses one, and on a proof-only Node only a proof copy. */
final class CiPlacesData extends Data
{
    public function __construct(
        public ?int $total,
        public bool $proof_only,
        public int $runner_jobs,
        public int $proof_copies,
        public ?int $free,
    ) {}
}
