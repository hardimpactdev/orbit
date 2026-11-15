<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Why one GitHub API read failed, in words that are safe to show: the family of the endpoint, never its URL or a token; the HTTP status
 * or the class of the transport error; and for a rate limit the reset time that GitHub gave.
 */
final readonly class GitHubReadFailure
{
    public function __construct(
        public GitHubReadFailureKind $kind,
        public string $endpoint,
        public ?int $status = null,
        public ?string $transport = null,
        public ?DateTimeImmutable $resetAt = null,
    ) {}

    public function describe(): string
    {
        return match (true) {
            $this->transport !== null => 'The '.$this->endpoint.' read failed with '.$this->transport.'.',
            $this->kind === GitHubReadFailureKind::RateLimited => 'The '.$this->endpoint.' read hit the GitHub rate limit (HTTP '.$this->status.')'
                .($this->resetAt === null ? ', and GitHub gave no reset time.' : ', which resets at '.$this->resetAt->format(DateTimeInterface::ATOM).'.'),
            default => 'The '.$this->endpoint.' read answered HTTP '.$this->status.'.',
        };
    }
}
