<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** A check operation's atomic Git commit receipt, bound to its pre-commit snapshot. */
final readonly class TaskWorkspaceCommitReceipt
{
    public function __construct(public int $operation, public string $head, public string $tree) {}

    public function ref(): string
    {
        return 'refs/orbit/check-operations/'.$this->operation;
    }
}
