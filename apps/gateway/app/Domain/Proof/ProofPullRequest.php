<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** A pull request that has a commit as its head. */
final readonly class ProofPullRequest
{
    /** @param list<string> $labels */
    public function __construct(public int $number, public bool $open, public string $headSha, public array $labels) {}
}
