<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** A proof run's copy on a CI Node. */
final class CiProofCopyData extends Data
{
    public function __construct(
        public int $instance_id,
        public string $instance_name,
        public int $task_group_id,
    ) {}
}
