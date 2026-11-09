<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/**
 * The cases a commit selects and the paths no case covers.
 *
 * @phpstan-type Case array{command: string, file: string, only?: list<string>, reasons?: array<string,string>}
 * @phpstan-type Uncovered array{path: string, reason: string}
 */
final readonly class ProofSelectionResult
{
    /** @param list<Case> $cases
     * @param  list<Uncovered>  $uncovered
     * @param  string|null  $everythingBecause  why every case was selected: all_requested, file_list_incomplete, selection_list_missing or wildcard
     * @param  string  $mergeBase  the merge base of the commit with the default branch, which the proof of a new case needs
     */
    public function __construct(public array $cases, public array $uncovered, public ?string $everythingBecause, public string $mergeBase) {}

    public function nothingToProve(): bool
    {
        return $this->cases === [];
    }

    /** @return list<string> */
    public function files(): array
    {
        return array_column($this->cases, 'file');
    }
}
