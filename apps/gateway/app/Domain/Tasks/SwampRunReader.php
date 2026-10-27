<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

interface SwampRunReader
{
    /** @return array{runs: list<array<string, mixed>>, truncated: bool} */
    public function read(int $groupId): array;
}
