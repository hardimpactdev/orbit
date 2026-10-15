<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskBaseMerge
{
    /** @param list<string> $conflictingPaths */
    public function __construct(public ?string $head, public array $conflictingPaths = []) {}
}
