<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class StartTaskAnalysisData extends Data
{
    /** @param array<string, mixed> $plan */
    public function __construct(public int $subtask, public array $plan, public string $plan_sha256, public string $base, public string $head, public string $tree) {}
}
