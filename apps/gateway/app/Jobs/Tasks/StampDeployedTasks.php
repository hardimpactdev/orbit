<?php

declare(strict_types=1);

namespace App\Jobs\Tasks;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\SourceControl\GitCommitSha;
use App\Models\InstanceDeployment;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Stamps `deployed_at` on the merged task groups that a succeeded production deploy carried ([Task insights](/reference/tasks#task-insights)).
 * It runs after the deploy's record committed, so it never fails or slows the deploy. Each such deploy compares every merged group
 * of the Project without a stamp, merged in the last 30 days, oldest first, up to 200. A group counts as carried when GitHub answers
 * that its merge commit is the deployed commit or an ancestor of it, and gets this deploy's finish time. A failed GitHub read leaves
 * the group for the next deploy; a stamp is never overwritten.
 */
final class StampDeployedTasks implements ShouldQueue
{
    use Queueable;

    private const int Days = 30;

    private const int Limit = 200;

    public function __construct(public int $deploymentId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->afterCommit();
    }

    public function handle(GitHubApi $github, RepositoryPullRequestAccess $access): void
    {
        $deployment = InstanceDeployment::query()->with('instance.project')->find($this->deploymentId);
        if (! $deployment instanceof InstanceDeployment || ! self::stamps($deployment)) {
            return;
        }
        $repository = GitHubRepository::fromOrigin($deployment->instance->project->repository_url ?? '');
        if ($repository === null) {
            return;
        }
        $candidates = Task::topLevel()->where('project_id', $deployment->instance->project_id)
            ->whereNotNull('merged_at')->whereNull('deployed_at')
            ->where('merged_at', '>=', $deployment->finished_at?->copy()->subDays(self::Days))->where('merged_at', '<=', $deployment->finished_at)
            ->orderBy('merged_at')->orderBy('id')->limit(self::Limit)->get(['id', 'pr_url', 'watched_pr_url', 'merged_sha']);
        foreach ($candidates as $group) {
            try {
                $sha = $this->mergeCommit($github, $access, $repository, $group);
                $carried = $sha !== null && $this->contains($github, $access, $repository, $sha, (string) $deployment->commit);
            } catch (GitHubApiException $exception) {
                Log::warning('Could not read whether a deploy carried a task group.', ['task_group_id' => $group->id, 'deployment_id' => $deployment->id, 'error' => $exception->getMessage()]);

                continue;
            }
            if ($carried) {
                Task::topLevel()->whereKey($group->id)->whereNull('deployed_at')->toBase()->update(['deployed_at' => $deployment->finished_at]);
            }
        }
    }

    /** A succeeded production deploy, not a rollback, with its commit and finish time recorded. */
    private static function stamps(InstanceDeployment $deployment): bool
    {
        return $deployment->kind === 'deploy' && $deployment->status === 'succeeded' && $deployment->finished_at !== null
            && is_string($deployment->commit) && GitCommitSha::isValid($deployment->commit) && $deployment->instance->placedOnAppProd();
    }

    /**
     * The merge commit of the pull request that set the group's `merged_at`: the watched one when there is one (TaskSettle), else
     * the merge step's recorded commit, else the published pull request's.
     */
    private function mergeCommit(GitHubApi $github, RepositoryPullRequestAccess $access, GitHubRepository $repository, Task $group): ?string
    {
        if ($group->watched_pr_url === null && is_string($group->merged_sha) && GitCommitSha::isValid($group->merged_sha)) {
            return $group->merged_sha;
        }
        $url = $group->watched_pr_url ?? $group->pr_url;
        $number = is_string($url) ? $repository->pullRequestNumber($url) : null;
        if ($number === null) {
            return null;
        }
        $pull = $github->pullRequest($access->cachedReadToken($repository), $repository, $number);
        $sha = $pull->state === GitHubPullRequestState::Merged ? $pull->mergeCommitSha : null;

        return is_string($sha) && GitCommitSha::isValid($sha) ? $sha : null;
    }

    private function contains(GitHubApi $github, RepositoryPullRequestAccess $access, GitHubRepository $repository, string $commit, string $deployed): bool
    {
        $comparison = $github->compareCommits($access->contentsReadToken($repository), $repository, $commit, $deployed);

        return $comparison->status === GitHubComparisonStatus::Identical || $comparison->headDescendsFromBase();
    }
}
