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
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The self-hosted jobs of the last 48 hours in every repository that the GitHub App reads, for the CI runner view
 * (docs/reference/ci-runner-view.md#where-each-value-comes-from). It uses the App's existing `Actions: read` and `Contents: read` and
 * never reads a runner list.
 *
 * Only the runs of workflows whose file can ask for a self-hosted runner are read; the workflows of a repository are kept for
 * {@see self::WorkflowSeconds}. Run lists and job lists are read to their last page. One answer serves every viewer for
 * {@see self::CacheSeconds}. The jobs of a completed run do not change, so they are kept until the run leaves the window, and only
 * the runs that have not completed are read again. Each answer reads workflow files and jobs that are not kept for at most
 * {@see self::BudgetSeconds}. A repository with a run or a file left unread is `truncated`, and a later answer starts with it.
 */
final readonly class GitHubActionsJobs
{
    public const int WindowHours = 48;

    public const int CacheSeconds = 60;

    public const int WorkflowSeconds = 3600;

    public const float BudgetSeconds = 20.0;

    private const string AnswerKey = 'orbit:ci-runners:jobs';

    private const string RunKey = 'orbit:ci-runners:run-jobs:';

    private const string WorkflowKey = 'orbit:ci-runners:workflows:';

    /** The repository that the last answer did not read to the end. */
    private const string NextKey = 'orbit:ci-runners:next';

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

        $ends = CarbonImmutable::now()->addMilliseconds((int) ($this->budgetSeconds * 1000));
        $until = CarbonImmutable::now()->utc();
        $since = $until->subHours(self::WindowHours);
        $repositories = [];
        $jobs = [];
        $unfinished = [];

        foreach (self::rotated(FleetReboot::ciRepositories(), Cache::get(self::NextKey)) as $slug) {
            $repository = GitHubRepository::fromOrigin('https://github.com/'.$slug);
            if ($repository === null) {
                continue;
            }

            $read = $this->repository($slug, $repository, $since, $until, $ends);
            if ($read === null) {
                continue;
            }

            [$repositories[], $found] = $read;
            array_push($jobs, ...$found);
            if ($read[0]->truncated) {
                $unfinished[] = $slug;
            }
        }

        // The repository that started this answer and is still unfinished goes behind the others that are, so it cannot starve them.
        $next = $unfinished[count($unfinished) > 1 && $unfinished[0] === Cache::get(self::NextKey) ? 1 : 0] ?? null;
        $next === null ? Cache::forget(self::NextKey) : Cache::forever(self::NextKey, $next);
        usort($repositories, static fn (CiRepositoryData $a, CiRepositoryData $b): int => $a->repository <=> $b->repository);
        usort($jobs, static fn (CiJobData $a, CiJobData $b): int => [$b->created_at, $b->job_id] <=> [$a->created_at, $a->job_id]);

        return new CiJobListData(null, $repositories, $jobs);
    }

    /**
     * The repositories in order, starting with the one that the last answer chose to finish first.
     *
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private static function rotated(array $slugs, mixed $next): array
    {
        $start = is_string($next) ? array_search($next, $slugs, true) : false;

        return $start === false ? $slugs : [...array_slice($slugs, $start), ...array_slice($slugs, 0, $start)];
    }

    /**
     * The self-hosted jobs of one repository's window, or null for a repository that the App is not installed on: its installation
     * answers 404. Run lists and kept jobs are always read; the budget only stops reads of workflow files and of jobs not kept.
     *
     * @return array{0: CiRepositoryData, 1: list<CiJobData>}|null
     */
    private function repository(string $slug, GitHubRepository $repository, DateTimeInterface $since, DateTimeInterface $until, CarbonImmutable $ends): ?array
    {
        $jobs = [];
        $token = null;
        $actions = function () use ($repository, &$token): string {
            return $token ??= $this->actions->token($repository);
        };

        try {
            [$workflows, $truncated] = $this->workflows($slug, $repository, $actions, $ends);

            foreach ($workflows as $workflow) {
                foreach ($this->actions->workflowRuns($repository, $actions(), $workflow, $since, $until) as $run) {
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

                    if (self::spent($ends)) {
                        $truncated = true;

                        continue;
                    }

                    $read = $this->jobs($slug, $id, is_string($run['name'] ?? null) ? $run['name'] : '', $this->actions->runJobs($repository, $actions(), $id));
                    if ($completed) {
                        Cache::put($key, array_map(static fn (CiJobData $job): array => $job->toArray(), $read), (self::WindowHours + 1) * 3600);
                    }
                    array_push($jobs, ...$read);
                }
            }
        } catch (GitHubActionsUnavailable $exception) {
            return $exception->status === 404 && $token === null ? null : [new CiRepositoryData($slug, 'github.unavailable', false), $jobs];
        }

        return [new CiRepositoryData($slug, null, $truncated), $jobs];
    }

    /**
     * The ids of the repository's workflows whose file can ask for a self-hosted runner, and whether a file was left unread because the
     * budget was spent. The list is kept for {@see self::WorkflowSeconds}, each file's verdict for as long, and a workflow that once
     * could ask for a self-hosted runner stays in the list while its runs can be in the window.
     *
     * @param  Closure(): string  $actions
     * @return array{0: list<int>, 1: bool}
     *
     * @throws GitHubActionsUnavailable
     */
    private function workflows(string $slug, GitHubRepository $repository, Closure $actions, CarbonImmutable $ends): array
    {
        $key = self::WorkflowKey.strtolower($slug);
        $kept = Cache::get($key);
        if (is_array($kept) && array_is_list($kept)) {
            return [array_values(array_filter($kept, is_int(...))), false];
        }

        $contents = null;
        $ids = [];
        $unread = false;
        foreach ($this->actions->workflows($repository, $actions()) as $workflow) {
            $id = $workflow['id'] ?? null;
            $path = $workflow['path'] ?? null;
            if (! is_int($id)) {
                continue;
            }

            $verdict = Cache::get($key.':'.$id);
            if (! is_bool($verdict)) {
                if (self::spent($ends)) {
                    $unread = true;

                    continue;
                }
                $contents ??= $this->actions->token($repository, 'contents');
                $file = is_string($path) && $path !== '' ? $this->actions->file($repository, $contents, $path) : null;
                $verdict = $file === null || GitHubWorkflowFile::canAskForSelfHosted($file);
                Cache::put($key.':'.$id, $verdict, self::WorkflowSeconds);
                if ($verdict) {
                    Cache::put($key.':'.$id.':once', true, (self::WindowHours + 1) * 3600);
                }
            }

            if ($verdict || Cache::get($key.':'.$id.':once') === true) {
                $ids[] = $id;
            }
        }

        if (! $unread) {
            Cache::put($key, $ids, self::WorkflowSeconds);
        }

        return [$ids, $unread];
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

    /**
     * Whether the answer's time to ask GitHub ended.
     *
     * @phpstan-impure
     */
    private static function spent(CarbonImmutable $ends): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($ends);
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
