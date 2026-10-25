<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class StartTaskScriptData extends Data
{
    /** @param list<string> $args */
    public function __construct(public int $subtask, public string $script, public array $args) {}
}
