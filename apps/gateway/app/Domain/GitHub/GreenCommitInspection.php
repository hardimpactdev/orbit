<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * One green-commit walk: the commit that may ship, if any, the branch head the walk started from, and the state of the
 * head's own required check, read in the same walk. The state is null when the walk did not read the head's check:
 * the head is the deployed commit or failed a deployment.
 */
final readonly class GreenCommitInspection
{
    public function __construct(
        public ?GreenCommit $green,
        public ?string $head,
        public ?RequiredCheckState $headState,
    ) {}
}
