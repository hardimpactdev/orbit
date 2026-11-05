<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** The files that differ between a commit and its merge base with the default branch. */
final readonly class ProofChangedFiles
{
    /** @param list<ProofChangedFile> $files
     * @param  bool  $complete  false when GitHub listed fewer files than the commit changes
     */
    public function __construct(public string $mergeBase, public array $files, public bool $complete) {}

    /** @return list<string> */
    public function paths(): array
    {
        return array_values(array_unique(array_merge(...array_map(static fn (ProofChangedFile $file): array => $file->paths(), $this->files))));
    }
}
