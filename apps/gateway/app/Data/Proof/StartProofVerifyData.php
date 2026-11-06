<?php

declare(strict_types=1);

namespace App\Data\Proof;

use Spatie\LaravelData\Data;

final class StartProofVerifyData extends Data
{
    public function __construct(public string $command, public ?int $group = null) {}
}
