<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * One check run on a commit, as the checks API reports it.
 */
final readonly class GitHubCheckRun
{
    /**
     * @param  string|null  $conclusion  null until the check run completes
     * @param  string|null  $url  the check run page, or the details page of the check's own service
     * @param  int|null  $id  the check run id, when GitHub sent one
     * @param  string|null  $startedAt  when the run started, when GitHub sent `started_at`
     * @param  string|null  $status  `queued`, `in_progress`, `completed`, or another state GitHub reports
     * @param  string|null  $headSha  the commit the run checked, when GitHub sent `head_sha`
     * @param  string|null  $appSlug  the slug of the GitHub App that created the run, when GitHub sent `app.slug`
     */
    public function __construct(
        public string $name,
        public ?string $conclusion,
        public ?string $url,
        public ?int $id = null,
        public ?string $startedAt = null,
        public ?string $status = null,
        public ?string $headSha = null,
        public ?string $appSlug = null,
        public ?string $outputTitle = null,
        public ?string $outputSummary = null,
    ) {}

    /** @return array{name: string, conclusion: string|null, url: string|null, id: int|null, started_at: string|null, status: string|null, head_sha: string|null, app_slug: string|null, output_title: string|null, output_summary: string|null} */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'conclusion' => $this->conclusion, 'url' => $this->url, 'id' => $this->id,
            'started_at' => $this->startedAt, 'status' => $this->status, 'head_sha' => $this->headSha,
            'app_slug' => $this->appSlug, 'output_title' => $this->outputTitle, 'output_summary' => $this->outputSummary,
        ];
    }

    /** The run that {@see toArray()} stored, or null for a value that is not one. */
    public static function fromArray(mixed $row): ?self
    {
        if (! is_array($row) || ! is_string($row['name'] ?? null)) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($row[$key] ?? null) ? $row[$key] : null;

        return new self($row['name'], $text('conclusion'), $text('url'), is_int($row['id'] ?? null) ? $row['id'] : null,
            $text('started_at'), $text('status'), $text('head_sha'), $text('app_slug'), $text('output_title'), $text('output_summary'));
    }

    /** Whether the run completed with conclusion `success` for exactly this commit. */
    public function passedOn(string $sha): bool
    {
        return $this->headSha === $sha && $this->status === 'completed' && $this->conclusion === 'success';
    }

    /**
     * Who answers for the run, by {@see GitHubCheckConclusion::ofRun()}. Nobody while it runs, nor for a conclusion
     * that GitHub adds later.
     */
    public function fault(): GitHubCheckFault
    {
        return GitHubCheckConclusion::tryFrom((string) $this->conclusion)?->ofRun() ?? GitHubCheckFault::None;
    }

    /** Whether the check run completed without passing. */
    public function failed(): bool
    {
        return $this->fault() !== GitHubCheckFault::None;
    }

    /** Whether the run failed because it did not run to completion, not because the check found a problem. */
    public function infrastructure(): bool
    {
        return $this->fault() === GitHubCheckFault::Infrastructure;
    }

    /** Whether the run has not completed yet. */
    public function pending(): bool
    {
        return $this->conclusion === null;
    }
}
