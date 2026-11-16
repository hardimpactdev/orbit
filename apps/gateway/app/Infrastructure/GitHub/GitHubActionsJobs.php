<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Data\Ci\CiJobData;
use App\Data\Ci\CiJobListData;
use App\Data\Ci\CiRepositoryData;
use App\Domain\Fleet\FleetReboot;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Cache;

/**
 * The self-hosted jobs of the last 48 hours in every repository that the GitHub App reads, for the CI runner view
 * (docs/reference/ci-runner-view.md#where-each-value-comes-from). It uses the App's existing `Actions: read` and never reads a
 * runner list.
 *
 * One answer serves every viewer for {@see self::CacheSeconds}. The jobs of a completed run do not change, so they are kept until the
 * run leaves the window, and only the runs that have not completed are read again. Each answer reads jobs for at most
 * {@see self::BudgetSeconds}, newest runs first. A repository whose runs the answer did not all read is `truncated`, and a later
 * answer goes on from the kept runs.
 */
final readonly class GitHubActionsJobs
{
    public const int WindowHours = 48;

    public const int CacheSeconds = 60;

    /** Pages of 100 runs read for each repository. */
    public const int RunPages = 5;

    public const float BudgetSeconds = 20.0;

    private const string AnswerKey = 'orbit:ci-runners:jobs';

    private const string RunKey = 'orbit:ci-runners:run-jobs:';

    public function __construct(
        private GitHubActionsReader $actions,
        private GitHubAppStore $apps,
        private float $budgetSeconds = self::BudgetSeconds,
    ) {}

    public function read(): CiJobListData
    {
        $cached = Cache::get(self::AnswerKey);
        if (is_array($cached)) {
            return CiJobListData::from($cached);
        }

        $answer = $this->fresh();
        Cache::put(self::AnswerKey, $answer->toArray(), self::CacheSeconds);

        return $answer;
    }

    private function fresh(): CiJobListData
    {
        if ($this->apps->credentials() === null) {
            return new CiJobListData('github.app_missing', [], []);
        }

        $ends = hrtime(true) + (int) ($this->budgetSeconds * 1e9);
        $since = now()->utc()->subHours(self::WindowHours);
        $repositories = [];
        $jobs = [];

        foreach (FleetReboot::ciRepositories() as $slug) {
            $repository = GitHubRepository::fromOrigin('https://github.com/'.$slug);
            if ($repository === null) {
                continue;
            }

            try {
                $token = $this->actions->token($repository);
                $listed = $this->actions->workflowRuns($repository, $token, $since, self::RunPages);
            } catch (GitHubActionsUnavailable $exception) {
                // A repository the App is not installed on is not read; its installation answers 404.
                if ($exception->status !== 404) {
                    $repositories[] = new CiRepositoryData($slug, 'github.unavailable', false);
                }

                continue;
            }

            $truncated = $listed['truncated'];
            $failed = false;

            foreach ($listed['runs'] as $run) {
                $id = $run['id'] ?? null;
                $attempt = $run['run_attempt'] ?? 1;
                if (! is_int($id) || ! is_int($attempt) || ($run['conclusion'] ?? null) === 'skipped') {
                    continue;
                }

                $completed = ($run['status'] ?? null) === 'completed';
                $key = self::RunKey.strtolower($slug).':'.$id.':'.$attempt;
                $kept = $completed ? Cache::get($key) : null;

                if (is_array($kept)) {
                    array_push($jobs, ...CiJobData::collect(array_values($kept)));

                    continue;
                }

                if ($failed || hrtime(true) >= $ends) {
                    $truncated = true;

                    continue;
                }

                try {
                    $read = $this->jobs($slug, $id, is_string($run['name'] ?? null) ? $run['name'] : '', $this->actions->runJobs($repository, $token, $id));
                } catch (GitHubActionsUnavailable) {
                    $failed = true;

                    continue;
                }

                if ($completed) {
                    Cache::put($key, array_map(static fn (CiJobData $job): array => $job->toArray(), $read), (self::WindowHours + 1) * 3600);
                }
                array_push($jobs, ...$read);
            }

            $repositories[] = new CiRepositoryData($slug, $failed ? 'github.unavailable' : null, $truncated);
        }

        usort($jobs, static fn (CiJobData $a, CiJobData $b): int => [$b->created_at, $b->job_id] <=> [$a->created_at, $a->job_id]);

        return new CiJobListData(null, $repositories, $jobs);
    }

    /**
     * The jobs of one run that asked for a self-hosted runner. GitHub gives a queued job a start time, so a start counts only once the
     * job runs, and an end only once it completed.
     *
     * @param  list<array<array-key, mixed>>  $rows
     * @return list<CiJobData>
     */
    private function jobs(string $slug, int $runId, string $workflow, array $rows): array
    {
        $jobs = [];

        foreach ($rows as $row) {
            $labels = $row['labels'] ?? null;
            $id = $row['id'] ?? null;
            $status = $row['status'] ?? null;
            $created = self::time($row['created_at'] ?? null);
            if (! is_array($labels) || ! in_array('self-hosted', $labels, true) || ! is_int($id) || ! is_string($status) || $created === null) {
                continue;
            }

            $started = in_array($status, ['in_progress', 'completed'], true) ? self::time($row['started_at'] ?? null) : null;
            $ended = $status === 'completed' ? self::time($row['completed_at'] ?? null) : null;
            $runner = $row['runner_name'] ?? null;
            $conclusion = $row['conclusion'] ?? null;
            $url = $row['html_url'] ?? null;

            $jobs[] = new CiJobData(
                job_id: $id,
                run_id: $runId,
                repository: $slug,
                workflow: $workflow,
                job: is_string($row['name'] ?? null) ? $row['name'] : '',
                runner_name: is_string($runner) && $runner !== '' ? $runner : null,
                status: $status,
                conclusion: is_string($conclusion) ? $conclusion : null,
                created_at: $created[0],
                started_at: $started[0] ?? null,
                completed_at: $ended[0] ?? null,
                queue_wait_seconds: $started === null ? null : self::seconds($created[1], $started[1]),
                run_seconds: $started === null || $ended === null ? null : self::seconds($started[1], $ended[1]),
                url: is_string($url) ? $url : null,
            );
        }

        return $jobs;
    }

    /** @return array{0: string, 1: CarbonImmutable}|null GitHub's text and its time */
    private static function time(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return [$value, CarbonImmutable::parse($value)];
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private static function seconds(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, (int) $from->diffInSeconds($to));
    }
}
