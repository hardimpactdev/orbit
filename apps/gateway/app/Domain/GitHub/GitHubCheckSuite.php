<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * One check suite on a commit, as the checks API reports it. GitHub Actions makes one suite per workflow run, when the
 * event that starts it fires. A suite can complete with no check runs: a run that a newer run cancelled, or a workflow
 * GitHub could not start.
 */
final readonly class GitHubCheckSuite
{
    /**
     * @param  string|null  $status  `queued`, `in_progress`, `completed`, or another state GitHub reports
     * @param  string|null  $conclusion  null until the suite completes
     */
    public function __construct(
        public int $id,
        public ?string $status,
        public ?string $conclusion,
        public ?string $appSlug,
    ) {}

    public function completed(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Who answers for the suite once it completed without the merge-check run, by
     * {@see GitHubCheckConclusion::ofSuiteWithoutMergeCheck()}. A conclusion that GitHub adds later is unexplained.
     */
    public function faultWithoutMergeCheck(): GitHubCheckFault
    {
        return GitHubCheckConclusion::tryFrom((string) $this->conclusion)?->ofSuiteWithoutMergeCheck() ?? GitHubCheckFault::Unexplained;
    }

    /** The suite as a reason names it, such as `check suite 123 (success)`. */
    public function label(): string
    {
        return 'check suite '.$this->id.' ('.($this->conclusion ?? 'no conclusion').')';
    }

    /** @return array{id: int, status: string|null, conclusion: string|null, app_slug: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'conclusion' => $this->conclusion, 'app_slug' => $this->appSlug];
    }

    /** The suite that {@see toArray()} stored, or null for a value that is not one. */
    public static function fromArray(mixed $row): ?self
    {
        if (! is_array($row) || ! is_int($row['id'] ?? null)) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;

        return new self($row['id'], $text('status'), $text('conclusion'), $text('app_slug'));
    }
}
