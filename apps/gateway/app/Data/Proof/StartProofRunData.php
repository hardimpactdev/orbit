<?php

declare(strict_types=1);

namespace App\Data\Proof;

use Spatie\LaravelData\Data;

final class StartProofRunData extends Data
{
    public function __construct(public string $commit, public bool $all = false) {}
}
