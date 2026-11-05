<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** One file of a commit's diff against its merge base, as GitHub lists it. */
final readonly class ProofChangedFile
{
    public function __construct(public string $path, public string $status, public ?string $previous = null) {}

    /**
     * A rename or a delete counts for the old path too: the old path of a rename is changed as well.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->status === 'renamed' && $this->previous !== null ? [$this->path, $this->previous] : [$this->path];
    }
}
