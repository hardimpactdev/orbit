<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Task;
use Illuminate\Console\Command;
use Throwable;

/** Fills `merged_at` of older ended tasks from GitHub ([Lifecycle times](/reference/tasks#lifecycle-times)). */
final class BackfillTaskMergedAtCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:tasks:backfill-merged-at';

    #[\Override]
    protected $description = 'Store GitHub\'s merge time on ended tasks whose pull request merged without a recorded time';

    public function handle(GitHubApi $github, RepositoryPullRequestAccess $access): int
    {
        $merged = 0;
        $notMerged = 0;
        $unreadable = 0;
        $groups = Task::topLevel()->with('project')
            ->whereIn('status', TaskGroupStatus::endedStatuses())
            ->whereNotNull('pr_url')->whereNull('merged_at')
            ->orderBy('id')->get();
        foreach ($groups as $group) {
            // A transferred repository keeps its pull request numbers, and GitHub redirects the old path.
            $repository = GitHubRepository::fromPullRequestUrl((string) $group->pr_url);
            $project = GitHubRepository::fromOrigin((string) $group->project->repository_url);
            $number = $repository?->pullRequestNumber((string) $group->pr_url);
            if (! $repository instanceof GitHubRepository || ! $project instanceof GitHubRepository || $number === null) {
                $unreadable++;

                continue;
            }
            try {
                $mergedAt = $github->pullRequest($access->token($project), $repository, $number)->mergedAt;
            } catch (Throwable) {
                $unreadable++;

                continue;
            }
            if ($mergedAt === null) {
                $notMerged++;

                continue;
            }
            $group->timestamps = false;
            $group->forceFill(['merged_at' => $mergedAt, 'ended_at' => $group->ended_at ?? $mergedAt])->saveQuietly();
            $merged++;
        }
        $this->info("Stored the merge time of {$merged} tasks. {$notMerged} did not merge; {$unreadable} could not be read.");

        return self::SUCCESS;
    }
}
