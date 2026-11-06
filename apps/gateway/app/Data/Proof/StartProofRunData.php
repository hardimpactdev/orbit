<?php

declare(strict_types=1);

namespace App\Data\Proof;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class StartProofRunData extends Data
{
    public function __construct(public string $commit, public bool $all = false, public bool $reportOnly = false) {}
}
