<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskCheckKind;
use Spatie\LaravelData\Data;

final class StartTaskCheckData extends Data
{
    public function __construct(public int $subtask, public TaskCheckKind $phase) {}
}
