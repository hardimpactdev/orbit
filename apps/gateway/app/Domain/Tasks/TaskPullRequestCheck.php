<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckRun;

/** One failed check run on an open settling pull request (ADR 0140, ADR 0164). */
final readonly class TaskPullRequestCheck
{
    /**
     * Aggregate checks that only report whether other checks passed. One of them is ignored while
     * another failed check explains the failure, so one real failure is one problem (ADR 0164).
     */
    public const array ROLLUP_NAMES = ['Required checks'];

    public function __construct(
        public string $name,
        public ?string $url,
        public ?int $id = null,
        public ?string $appSlug = null,
        public ?string $outputTitle = null,
        public ?string $outputSummary = null,
    ) {}

    public function rollup(): bool
    {
        return in_array($this->name, self::ROLLUP_NAMES, true);
    }

    public static function fromRun(GitHubCheckRun $run): self
    {
        return new self($run->name, $run->url, $run->id, $run->appSlug, $run->outputTitle, $run->outputSummary);
    }

    /**
     * Drops rollup checks, such as `Required checks`, when another failed run explains the failure.
     * A rollup that fails alone stays.
     *
     * @param  list<GitHubCheckRun>  $failed
     * @return list<GitHubCheckRun>
     */
    public static function withoutExplainedRollups(array $failed): array
    {
        $rollup = static fn (GitHubCheckRun $run): bool => in_array($run->name, self::ROLLUP_NAMES, true);
        $explained = array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $rollup($run)) !== [];

        return $explained ? array_values(array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $rollup($run))) : $failed;
    }
}
