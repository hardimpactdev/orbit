<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewComment;
use App\Domain\GitHub\GitHubReviewOverflowException;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskBranchUpdate;
use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskReviewCandidate;
use App\Domain\Tasks\TaskReviewCandidateResult;
use App\Domain\Tasks\TaskReviewObservation;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Domain\Tasks\TaskReviewSelection;
use App\Domain\Tasks\TaskReviewTrust;
use App\Models\Task;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the state of the pull request Orbit opened, through the Gateway GitHub App.
 */
final readonly class HttpTaskPullRequestWatcher implements TaskPullRequestReviewWatcher, TaskPullRequestUpdater, TaskPullRequestWatcher
{
    private const int CHECKS_CACHE_SECONDS = 60;

    /** A run pending for longer than this is infrastructure. One pending for this long or less is not a result yet. */
    private const int PENDING_YOUNG_MINUTES = 60;

    private const int PENDING_CLOCK_SECONDS = 7 * 24 * 60 * 60;

    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function updateBranch(Task $group, string $headSha): TaskBranchUpdate
    {
        $target = $this->target($group);
        if ($target === null) {
            return TaskBranchUpdate::Unavailable;
        }
        [$repository, $number] = $target;
        $key = 'tasks:branch-update:'.$group->id.':'.$headSha;
        $read = function () use ($repository, $number, $headSha): string {
            try {
                return $this->github->updatePullRequestBranch($this->access->token($repository), $repository, $number, $headSha)->value;
            } catch (Throwable) {
                return TaskBranchUpdate::Unavailable->value;
            }
        };
        $cached = self::cachedBranchUpdate(Cache::remember($key, 60, $read));
        if ($cached !== null) {
            return $cached;
        }
        Cache::forget($key);

        return self::cachedBranchUpdate(Cache::remember($key, 60, $read)) ?? TaskBranchUpdate::Unavailable;
    }

    private static function cachedBranchUpdate(mixed $value): ?TaskBranchUpdate
    {
        return is_string($value) ? TaskBranchUpdate::tryFrom($value) : null;
    }

    public function status(Task $group): ?string
    {
        $target = $this->target($group);
        if ($target === null) {
            return null;
        }
        [$repository, $number] = $target;
        try {
            return $this->github->pullRequest($this->access->token($repository), $repository, $number)->state->value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check runs need the separate `checks: read` token. Without it, only conflicts are reported.
     * A rollup check is dropped while another failed check explains the failure. Cancelled runs, runs
     * that could not start, and runs pending for more than 60 minutes are infrastructure, kept apart
     * from genuine failures (ADR 0164).
     */
    public function health(Task $group): ?TaskPullRequestHealth
    {
        // Evidence is refreshed even when health cannot offer a repair. It grants no authority.
        $reviewBackoff = Cache::get('tasks.github-review-read.'.$group->id);
        if (! is_array($reviewBackoff) || ! is_int($reviewBackoff['due'] ?? null) || $reviewBackoff['due'] <= now()->getTimestamp()) {
            $this->reviews($group, fresh: true);
        }
        $target = $this->target($group);
        if ($target === null) {
            return null;
        }
        [$repository, $number] = $target;
        try {
            $pullRequest = $this->github->pullRequest($this->access->token($repository), $repository, $number);
            if ($pullRequest->state !== GitHubPullRequestState::Open) {
                return new TaskPullRequestHealth(
                    $pullRequest->state->value,
                    headSha: $pullRequest->headSha,
                    pullRequestNumber: $number,
                    mergeBody: $pullRequest->body,
                    mergeSha: $pullRequest->mergeCommitSha,
                    mergedAt: $pullRequest->mergedAt,
                );
            }
            ['failed' => $failed, 'pending' => $pending] = $this->headChecks($repository, $number, $pullRequest);
        } catch (Throwable) {
            return null;
        }

        $failed = TaskPullRequestCheck::withoutExplainedRollups($failed);
        $young = false;
        $stale = [];
        if ($pullRequest->headSha !== null) {
            ['young' => $young, 'stale' => $stale] = $this->classifyPending(
                $this->pendingHeadKey($repository, $number, $pullRequest->headSha),
                $pending,
            );
        }
        $problems = [];
        if ($pullRequest->conflicts()) {
            $base = $pullRequest->baseRef ?? 'the base branch';
            $problems[] = 'It conflicts with '.$base.'; merge '.$base.' into the task branch and push.';
        }
        foreach ($failed as $run) {
            $problems[] = 'Check '.$run->name.' failed'.($run->url !== null ? ': '.$run->url : '').'.';
        }
        foreach ($stale as $run) {
            $problems[] = 'Check '.$run->name.' is still pending'.($run->url !== null ? ': '.$run->url : '').'.';
        }
        $check = TaskPullRequestCheck::fromRun(...);
        $infrastructure = array_values(array_map($check, array_filter($failed, static fn (GitHubCheckRun $run): bool => $run->infrastructure())));
        foreach ($stale as $run) {
            $infrastructure[] = $check($run);
        }

        return new TaskPullRequestHealth(
            state: 'open',
            problems: $problems,
            baseRef: $pullRequest->baseRef,
            conflicts: $pullRequest->conflicts(),
            failedChecks: array_values(array_map($check, array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $run->infrastructure()))),
            headSha: $pullRequest->headSha,
            infrastructureChecks: $infrastructure,
            checksPending: $pending !== [],
            checksYoungPending: $young,
            mergeable: $pullRequest->mergeable,
            behind: $pullRequest->mergeableState === 'behind',
        );
    }

    /**
     * Complete bounded selection. PR state/head and operator trust are always fresh. Only successful
     * review lists are cached, for at most 60 seconds; errors are not healthy empty observations.
     */
    public function reviews(Task $group, bool $fresh = false): TaskReviewObservation
    {
        $records = new TaskGitHubReviewObservations;
        $sequence = $records->begin($group);
        $cached = false;
        $observation = $this->readReviews($group, $fresh, $cached);
        if ($cached) {
            $records->finishCached($group, $sequence);
        } else {
            $records->finish($group, $sequence, $observation);
        }

        return $observation;
    }

    private function readReviews(Task $group, bool $fresh, bool &$cached): TaskReviewObservation
    {
        $target = $this->target($group);
        if ($target === null) {
            return new TaskReviewObservation(TaskReviewReadStatus::Unreadable);
        }
        [$repository, $number] = $target;
        $trust = TaskReviewTrust::fromConfig($repository, config('orbit.tasks.github_reviewers', []));
        if (! $trust->valid || $trust->accountIds === []) {
            return new TaskReviewObservation(
                $trust->valid ? TaskReviewReadStatus::Disabled : TaskReviewReadStatus::InvalidTrust,
                $repository, $number, $trust,
            );
        }
        $pr = null;
        try {
            $token = $this->access->reviewsToken($repository);
            $pr = $this->github->pullRequest($token, $repository, $number);
            if ($pr->state === GitHubPullRequestState::Open && ($pr->headSha === null || $pr->headSha === '')) {
                return new TaskReviewObservation(TaskReviewReadStatus::Unreadable, $repository, $number, $trust, $pr);
            }
            $key = 'tasks:pull-request-reviews:v1:'.strtolower($repository->owner.'/'.$repository->name)
                .'#'.$number.'@'.$pr->headSha.':'.$trust->revision;
            $read = function () use ($token, $repository, $number, &$cached): array {
                $cached = false;

                return self::reviewCachePayload($this->github->reviews($token, $repository, $number));
            };
            $cached = ! $fresh;
            $payload = $fresh ? $read() : Cache::remember($key, 60, $read);
            $reviews = self::cachedReviews($payload);
            if ($reviews === null) {
                Cache::forget($key);
                $payload = Cache::remember($key, 60, $read);
                $reviews = self::cachedReviews($payload);
            } elseif ($fresh) {
                Cache::put($key, $payload, 60);
            }
            if ($reviews === null) {
                return new TaskReviewObservation(TaskReviewReadStatus::Unreadable, $repository, $number, $trust, $pr);
            }
            $selection = TaskReviewSelection::select($reviews, $trust, $pr->headSha, $pr->state === GitHubPullRequestState::Open);

            return new TaskReviewObservation(TaskReviewReadStatus::Complete, $repository, $number, $trust, $pr, $reviews, $selection);
        } catch (GitHubReviewOverflowException) {
            $cached = false;

            return new TaskReviewObservation(TaskReviewReadStatus::Overflow, $repository, $number, $trust, $pr);
        } catch (Throwable) {
            $cached = false;

            return new TaskReviewObservation(TaskReviewReadStatus::Unreadable, $repository, $number, $trust, $pr);
        }
    }

    /**
     * FileStore disables class deserialization. Store only scalars, not domain objects or dates.
     *
     * @param  list<GitHubReview>  $reviews
     * @return list<array<string, int|string|null>>
     */
    private static function reviewCachePayload(array $reviews): array
    {
        return array_map(static fn (GitHubReview $review): array => [
            'id' => $review->id,
            'reviewer_id' => $review->reviewerId,
            'reviewer_login' => $review->reviewerLogin,
            'state' => $review->state->value,
            'commit_id' => $review->commitId,
            'submitted_at' => $review->submittedAt?->format('Y-m-d\\TH:i:s.uP'),
            'url' => $review->url,
            'body' => $review->body,
        ], $reviews);
    }

    /** @return list<GitHubReview>|null */
    private static function cachedReviews(mixed $cached): ?array
    {
        if (! is_array($cached) || ! array_is_list($cached) || count($cached) > 1000) {
            return null;
        }
        $reviews = [];
        foreach ($cached as $row) {
            if (! is_array($row) || array_keys($row) !== ['id', 'reviewer_id', 'reviewer_login', 'state', 'commit_id', 'submitted_at', 'url', 'body']
                || ! is_int($row['id']) || $row['id'] < 1
                || ! is_int($row['reviewer_id']) || $row['reviewer_id'] < 1
                || ! is_string($row['reviewer_login']) || $row['reviewer_login'] === ''
                || ! is_string($row['state']) || ! is_string($row['commit_id'])
                || ! is_string($row['url']) || ! is_string($row['body'])) {
                return null;
            }
            $state = GitHubReviewState::tryFrom($row['state']);
            if ($state === null) {
                return null;
            }
            $submittedAt = null;
            if ($row['submitted_at'] !== null) {
                if (! is_string($row['submitted_at'])) {
                    return null;
                }
                $submittedAt = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.uP', $row['submitted_at']);
                if ($submittedAt === false || $submittedAt->format('Y-m-d\\TH:i:s.uP') !== $row['submitted_at']) {
                    return null;
                }
            }
            $reviews[] = new GitHubReview($row['id'], $row['reviewer_id'], $row['reviewer_login'], $state,
                $row['commit_id'], $submittedAt, $row['url'], $row['body']);
        }

        return $reviews;
    }

    /** Retrieve a caller-selected eligible request; cap/consumption policy belongs to the scheduler. */
    public function reviewCandidate(Task $group, int $reviewId, bool $fresh = false): TaskReviewCandidateResult
    {
        $observation = $this->reviews($group, $fresh);
        if ($observation->status !== TaskReviewReadStatus::Complete) {
            return new TaskReviewCandidateResult($observation->status);
        }
        $repository = $observation->repository;
        $number = $observation->number;
        $head = $observation->pullRequest?->headSha;
        $trust = $observation->trust;
        $selection = $observation->selection;
        if ($repository === null || $number === null || $head === null || $trust === null || $selection === null) {
            return new TaskReviewCandidateResult(TaskReviewReadStatus::Changed);
        }
        $selected = array_find($selection->requests, static fn (GitHubReview $request): bool => $request->id === $reviewId);
        if (! $selected instanceof GitHubReview) {
            return new TaskReviewCandidateResult(TaskReviewReadStatus::Changed);
        }
        try {
            $token = $this->access->reviewsToken($repository);
            $review = $this->github->review($token, $repository, $number, $reviewId);
            $comments = $this->github->reviewComments($token, $repository, $number, $reviewId);
            if ($review != $selected) {
                return new TaskReviewCandidateResult(TaskReviewReadStatus::Changed);
            }
            usort($comments, static fn (GitHubReviewComment $a, GitHubReviewComment $b): int => $a->id <=> $b->id);

            return new TaskReviewCandidateResult(TaskReviewReadStatus::Complete,
                new TaskReviewCandidate($repository, $number, $head, $trust->revision, $review, $comments));
        } catch (GitHubReviewOverflowException) {
            return new TaskReviewCandidateResult(TaskReviewReadStatus::Overflow);
        } catch (Throwable) {
            return new TaskReviewCandidateResult(TaskReviewReadStatus::Unreadable);
        }
    }

    /** Final PR/list/selected-review/comments reads all bypass the observation cache. */
    public function revalidateReviewCandidate(Task $group, TaskReviewCandidate $candidate): TaskReviewCandidateResult
    {
        $result = $this->reviewCandidate($group, $candidate->review->id, fresh: true);
        if ($result->status !== TaskReviewReadStatus::Complete) {
            return $result;
        }
        if ($result->candidate?->digest() !== $candidate->digest()) {
            return new TaskReviewCandidateResult(TaskReviewReadStatus::Changed);
        }

        return $result;
    }

    /**
     * Failed and not-yet-completed check runs on the head commit, read at most once a minute per commit.
     * The scheduler ticks every few seconds, and each read costs a checks token and a check-run list
     * against the App's rate limit. Only names, conclusions, ids, start times, and URLs are cached, never
     * a token. A new push has a new head, so it reads fresh. How long a run with no `started_at` has been
     * pending is stored apart from this cache, so a later read does not move that time.
     *
     * @return array{failed: list<GitHubCheckRun>, pending: list<GitHubCheckRun>}
     */
    private function headChecks(GitHubRepository $repository, int $number, GitHubPullRequest $pullRequest): array
    {
        if ($pullRequest->headSha === null) {
            return ['failed' => [], 'pending' => []];
        }
        $key = 'tasks:pull-request-checks:v4:'.$repository->owner.'/'.$repository->name.'#'.$number.'@'.$pullRequest->headSha;
        $read = function () use ($repository, $pullRequest): ?array {
            $token = $this->access->checksToken($repository);
            if ($token === null) {
                // A null cache value is a miss, so permission is checked again next tick.
                return null;
            }
            $runs = $this->github->checkRuns($token, $repository, $pullRequest->headSha);

            return [
                'failed' => array_values(array_map(
                    static fn (GitHubCheckRun $run): array => ['name' => $run->name, 'conclusion' => (string) $run->conclusion, 'url' => $run->url, 'id' => $run->id, 'app_slug' => $run->appSlug, 'output_title' => $run->outputTitle, 'output_summary' => $run->outputSummary],
                    array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->failed()),
                )),
                'pending' => array_values(array_map(
                    static fn (GitHubCheckRun $run): array => ['id' => $run->id, 'name' => $run->name, 'url' => $run->url, 'started_at' => $run->startedAt],
                    array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->pending()),
                )),
            ];
        };
        $cached = Cache::remember($key, self::CHECKS_CACHE_SECONDS, $read);
        if ($cached === null) {
            return ['failed' => [], 'pending' => []];
        }
        $checks = $this->cachedHeadChecks($cached);
        if ($checks !== null) {
            return $checks;
        }
        Cache::forget($key);

        return $this->cachedHeadChecks(Cache::remember($key, self::CHECKS_CACHE_SECONDS, $read)) ?? ['failed' => [], 'pending' => []];
    }

    /** @return array{failed: list<GitHubCheckRun>, pending: list<GitHubCheckRun>}|null */
    private function cachedHeadChecks(mixed $cached): ?array
    {
        return is_array($cached) ? [
            'failed' => $this->failedRuns($cached['failed'] ?? null),
            'pending' => $this->pendingRuns($cached['pending'] ?? null),
        ] : null;
    }

    /** @return list<GitHubCheckRun> */
    private function failedRuns(mixed $runs): array
    {
        if (! is_array($runs) || ! array_is_list($runs)) {
            return [];
        }
        $checks = [];
        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }
            $name = $run['name'] ?? null;
            $conclusion = $run['conclusion'] ?? null;
            $url = $run['url'] ?? null;
            if (! is_string($name) || ! is_string($conclusion) || ($url !== null && ! is_string($url))) {
                continue;
            }
            $checks[] = new GitHubCheckRun(
                name: $name, conclusion: $conclusion, url: $url,
                id: is_int($run['id'] ?? null) ? $run['id'] : null,
                appSlug: is_string($run['app_slug'] ?? null) ? $run['app_slug'] : null,
                outputTitle: is_string($run['output_title'] ?? null) ? $run['output_title'] : null,
                outputSummary: is_string($run['output_summary'] ?? null) ? $run['output_summary'] : null,
            );
        }

        return $checks;
    }

    /** @return list<GitHubCheckRun> */
    private function pendingRuns(mixed $runs): array
    {
        if (! is_array($runs) || ! array_is_list($runs)) {
            return [];
        }
        $checks = [];
        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }
            $id = $run['id'] ?? null;
            $name = $run['name'] ?? null;
            $url = $run['url'] ?? null;
            $startedAt = $run['started_at'] ?? null;
            if (
                ($id !== null && ! is_int($id))
                || ! is_string($name)
                || ($url !== null && ! is_string($url))
                || ($startedAt !== null && ! is_string($startedAt))
            ) {
                continue;
            }
            $checks[] = new GitHubCheckRun($name, null, $url, $id, $startedAt);
        }

        return $checks;
    }

    /**
     * A run with no conclusion is pending. `started_at` ages it from GitHub's clock. Without that time,
     * the first read on this head starts the clock and a later read leaves it. More than 60 minutes is
     * infrastructure. Each run/head clock expires after seven days without an index or head pointer.
     *
     * @param  list<GitHubCheckRun>  $pending
     * @return array{young: bool, stale: list<GitHubCheckRun>}
     */
    private function classifyPending(string $headKey, array $pending): array
    {
        $young = false;
        $stale = [];
        foreach ($pending as $run) {
            $runKey = $run->id !== null ? (string) $run->id : $run->name;
            $started = $run->startedAt !== null ? $this->parsedStart($run->startedAt) : null;
            if (! $started instanceof Carbon) {
                $started = $this->pendingSince($headKey, $runKey);
            }
            if ($started->lt(now()->subMinutes(self::PENDING_YOUNG_MINUTES))) {
                $stale[] = $run;
            } else {
                $young = true;
            }
        }

        return ['young' => $young, 'stale' => $stale];
    }

    private function parsedStart(string $startedAt): ?Carbon
    {
        try {
            return Carbon::parse($startedAt);
        } catch (Throwable) {
            return null;
        }
    }

    /** The first tick that saw this run unfinished on the head. A later read returns the same instant. */
    private function pendingSince(string $headKey, string $runKey): Carbon
    {
        $key = $this->pendingClockKey($headKey, $runKey);
        $stored = Cache::remember($key, self::PENDING_CLOCK_SECONDS, static fn (): string => now()->toIso8601String());

        return Carbon::parse($stored);
    }

    private function pendingHeadKey(GitHubRepository $repository, int $number, string $headSha): string
    {
        return $repository->owner.'/'.$repository->name.'#'.$number.'@'.$headSha;
    }

    private function pendingClockKey(string $headKey, string $runKey): string
    {
        return 'tasks:check-pending-since:v2:'.$headKey.':'.$runKey;
    }

    /** @return array{GitHubRepository, int}|null */
    private function target(Task $group): ?array
    {
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        if (! $repository instanceof GitHubRepository || ! is_string($group->pr_url)) {
            return null;
        }
        $number = $repository->pullRequestNumber($group->pr_url);

        return $number === null ? null : [$repository, $number];
    }
}
