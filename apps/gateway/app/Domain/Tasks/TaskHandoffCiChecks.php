<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckFault;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Task;
use App\Models\TaskCheck;

/** Shared handoff push, classification and output. Neither caller delegates lifecycle decisions here. */
final readonly class TaskHandoffCiChecks
{
    private const string SkipDirectives = '/\[(?:skip ci|ci skip|no ci|skip actions|actions skip)\]|^[ \t]*skip-checks[ \t]*:[ \t]*true[ \t]*$/im';

    public function __construct(
        private TaskPullRequestPublisher $publisher,
        private TaskPullRequestMerger $merger,
        private TaskPullRequestCheckOutput $checkOutput,
        private TaskCheckOutputBuilder $output,
    ) {}

    public function push(Task $group, TaskCheck $check, string $commit): void
    {
        $this->publisher->pushHandoff($group, $commit);
        TaskCheck::query()->whereKey($check->id)->whereNull('pushed_at')->update(['pushed_at' => now(), 'updated_at' => now()]);
        $check->refresh();
    }

    public function read(Task $group, TaskCheck $check, string $commit, string $checkName): TaskHandoffCiReading
    {
        try {
            $checks = $this->merger->commitChecks($group, $commit);
        } catch (TaskPullRequestException $exception) {
            return new TaskHandoffCiReading(RequiredCheckState::Unreadable, 'infrastructure', $exception->getMessage());
        }
        if ($checks === null) {
            return new TaskHandoffCiReading(RequiredCheckState::Unreadable, 'configuration', 'Orbit could not read the checks of '.$commit.': the GitHub App has no checks: read permission.');
        }
        $state = RequiredCheckState::of($checks['runs'], $commit, $checkName);
        $reading = match ($state) {
            RequiredCheckState::Missing => $this->missing($group, $check, $commit, $checkName, $checks['runs'], $checks['suites']),
            RequiredCheckState::Failed => $this->failed($group, $commit, $checkName, $checks['runs']),
            default => new TaskHandoffCiReading($state),
        };

        return new TaskHandoffCiReading($reading->state, $reading->fault, $reading->output,
            array_map(fn (GitHubCheckRun $run): array => $this->redacted($run->toArray()), $checks['runs']),
            array_map(fn (GitHubCheckSuite $suite): array => $this->redacted($suite->toArray()), $checks['suites']));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function redacted(array $row): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) ? $this->output->tail($value) : $value, $row);
    }

    /**
     * The commit's current suites have no merge-check run yet. The shared read removes superseded suites and their
     * jobs. No suite yet, or one not completed: pending. No suite {@see NoSuiteSeconds} after the push asks for
     * assistance once, because nothing in Orbit can start CI. Once every current suite completed without the merge
     * check, {@see GitHubCheckSuite::faultWithoutMergeCheck()} decides who answers.
     *
     * @param  list<GitHubCheckRun>  $runs
     * @param  list<GitHubCheckSuite>  $suites
     */
    private function missing(Task $group, TaskCheck $check, string $commit, string $checkName, array $runs, array $suites): TaskHandoffCiReading
    {
        $suites = array_values(array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->appSlug === RequiredCheckState::CheckApp));
        if ($suites === []) {
            if ($check->pushed_at?->isBefore(now()->subSeconds(TaskHandoffCi::NoSuiteSeconds)) === true) {
                return new TaskHandoffCiReading(RequiredCheckState::Missing, 'configuration', 'GitHub Actions started no workflow on handoff commit '.$commit.' in the '.(TaskHandoffCi::NoSuiteSeconds / 60)
                    .' minutes since Orbit pushed it to '.TaskRemoteBranch::handoff($group).'. A ci.yml on the task branch without the task-*-handoff push trigger, '
                    .'such as one from before that trigger existed, or a skipped workflow causes this. Merge main into the task branch.');
            }

            return new TaskHandoffCiReading(RequiredCheckState::Missing);
        }
        if (! array_all($suites, static fn (GitHubCheckSuite $suite): bool => $suite->completed())) {
            return new TaskHandoffCiReading(RequiredCheckState::Missing);
        }
        $change = array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->faultWithoutMergeCheck() === GitHubCheckFault::Change);
        $infrastructure = array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->faultWithoutMergeCheck() === GitHubCheckFault::Infrastructure);
        if ($change !== []) {
            $failed = self::changeFailures($runs);
            $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);

            return new TaskHandoffCiReading(RequiredCheckState::Failed, 'change', 'GitHub Actions ended on '.$commit.' without check '.$checkName.': '.self::named($change).'.'.($failed !== []
                ? "\n\n".$this->evidence($group, $failed)
                : ' No failed job explains it: GitHub ends a workflow this way when it cannot parse or start a file under .github/workflows/. Fix the workflow, then hand off again.'
                    .($repository instanceof GitHubRepository ? ' See https://github.com/'.$repository->owner.'/'.$repository->name.'/commit/'.$commit.'/checks.' : '')));

        }
        if ($infrastructure !== []) {
            return new TaskHandoffCiReading(RequiredCheckState::Failed, 'infrastructure', 'CI did not run to completion on handoff commit '.$commit.': '.'GitHub Actions cancelled '.self::named($infrastructure).' before check '.$checkName.' ran. Check the runners.');
        }

        return new TaskHandoffCiReading(RequiredCheckState::Missing, 'configuration', 'GitHub Actions completed every workflow on handoff commit '.$commit.' without check '.$checkName.': '.self::named($suites)
            .'. A renamed or removed job, or a workflow that no longer runs on pushes to task-*-handoff branches, causes this. Fix the workflow or the Project merge check.');
    }

    /**
     * The merge check did not pass. {@see GitHubCheckRun::fault()} judges each run. Failed jobs of the change go to the
     * implementer as a failed check. When only infrastructure failed, such as a cancelled job, Orbit asks for
     * assistance. When no run failed, such as a skipped, neutral, or stale merge-check run, or a run of that name from
     * another App, nothing is charged to the implementer either: Orbit asks for assistance that names the runs.
     *
     * @param  list<GitHubCheckRun>  $runs
     */
    private function failed(Task $group, string $commit, string $checkName, array $runs): TaskHandoffCiReading
    {
        $change = self::changeFailures($runs);
        if ($change !== []) {
            return new TaskHandoffCiReading(RequiredCheckState::Failed, 'change', $this->evidence($group, $change));

        }
        $failures = TaskPullRequestCheck::failures($runs);
        if ($failures !== []) {
            return new TaskHandoffCiReading(RequiredCheckState::Failed, 'infrastructure', 'CI did not run to completion on handoff commit '.$commit.': '.
                implode(', ', array_map(static fn (GitHubCheckRun $run): string => $run->name.' ('.$run->conclusion.')', $failures)).'. Check the runners.');
        }
        $named = array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->name === $checkName);

        return new TaskHandoffCiReading(RequiredCheckState::Failed, 'configuration', 'Check '.$checkName.' did not pass on handoff commit '.$commit.', but no job failed: '
            .implode(', ', array_map(static fn (GitHubCheckRun $run): string => $run->name.' ('.($run->appSlug ?? 'unknown App').', '.($run->conclusion ?? $run->status ?? 'no conclusion').')', $named))
            .'. A skipped, neutral, or stale run, or a run of that name from another App, causes this. Fix the workflow or the Project merge check.');
    }

    /**
     * The failed runs that the change answers for, as settling judges them too.
     *
     * @param  list<GitHubCheckRun>  $runs
     * @return list<GitHubCheckRun>
     */
    private static function changeFailures(array $runs): array
    {
        return array_values(array_filter(TaskPullRequestCheck::failures($runs), static fn (GitHubCheckRun $run): bool => ! $run->infrastructure()));
    }

    /** @param  list<GitHubCheckRun>  $runs */
    private function evidence(Task $group, array $runs): string
    {
        return implode("\n\n", array_map(function (GitHubCheckRun $run) use ($group): string {
            $failure = TaskPullRequestCheck::fromRun($run);

            return 'Check '.$failure->name.' failed'.($failure->url !== null ? ' ('.$failure->url.')' : '').".\n\n".$this->checkOutput->read($group, $failure);
        }, $runs));
    }

    /** The subtask title and the implementer's summary, without a directive that would make GitHub skip CI. */
    public static function message(Task $task, string $summary = ''): string
    {
        return (string) preg_replace(self::SkipDirectives, '', $task->title."\n\n".$summary);
    }

    /** @param  array<GitHubCheckSuite>  $suites */
    private static function named(array $suites): string
    {
        return implode(', ', array_map(static fn (GitHubCheckSuite $suite): string => $suite->label(), $suites));
    }
}
