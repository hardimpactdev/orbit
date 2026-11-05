<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/**
 * The cases a commit selects and the paths no case covers.
 *
 * @phpstan-type Case array{program: string, command: string}
 * @phpstan-type Uncovered array{path: string, reason: string}
 */
final readonly class ProofSelectionResult
{
    /** @param list<Case> $cases
     * @param  list<Uncovered>  $uncovered
     * @param  string|null  $everythingBecause  why every case was selected: all_requested, selection_list_changed, file_list_incomplete, selection_list_missing or wildcard
     */
    public function __construct(public array $cases, public array $uncovered, public ?string $everythingBecause) {}

    public function nothingToProve(): bool
    {
        return $this->cases === [];
    }

    /** @return list<string> */
    public function programs(): array
    {
        return array_column($this->cases, 'program');
    }
}
