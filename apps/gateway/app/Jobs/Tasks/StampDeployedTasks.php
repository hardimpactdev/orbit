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
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Stamps `deployed_at` on the merged task groups that a succeeded production deploy carried ([Task insights](/reference/tasks#task-insights)).
 * A production deploy is a succeeded `deploy` of an app-prod Instance, or a verified Gateway release of the Project whose repository
 * the release ships. It runs after the deploy's record committed, so it never fails or slows the deploy. Each such deploy compares every
 * merged group of the Project without a stamp, merged in the last 30 days, oldest first, up to 200. A group counts as carried when GitHub
 * answers that its merge commit is the deployed commit or an ancestor of it, and gets this deploy's finish time. A failed GitHub read
 * leaves the group for the next deploy; a stamp is never overwritten.
 */
final class StampDeployedTasks implements ShouldQueue
{
    use Queueable;

    private const int Days = 30;

    private const int Limit = 200;

    /** A verified Gateway release: its `owner/name` repository, commit and verify time. Declared defaults keep earlier payloads readable. */
    public ?string $repository = null;

    public ?string $commit = null;

    public ?string $deployedAt = null;

    public function __construct(public ?int $deploymentId = null)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->afterCommit();
    }

    /** The stamp after a verified Gateway release, which is neither a rollback nor a reconciliation. */
    public static function gatewayRelease(string $repository, string $commit, CarbonInterface $verifiedAt): self
    {
        $job = new self;
        $job->repository = $repository;
        $job->commit = $commit;
        $job->deployedAt = $verifiedAt->toIso8601String();

        return $job;
    }

    public function handle(GitHubApi $github, RepositoryPullRequestAccess $access): void
    {
        if ($this->deploymentId !== null) {
            $deployment = InstanceDeployment::query()->with('instance.project')->find($this->deploymentId);
            if (! $deployment instanceof InstanceDeployment || ! self::stamps($deployment) || $deployment->finished_at === null) {
                return;
            }
            $repository = GitHubRepository::fromOrigin($deployment->instance->project->repository_url ?? '');
            if ($repository !== null) {
                $this->stamp($github, $access, $deployment->instance->project_id, $repository, (string) $deployment->commit, CarbonImmutable::instance($deployment->finished_at));
            }

            return;
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/'.$this->repository);
        if ($repository === null || ! is_string($this->commit) || ! GitCommitSha::isValid($this->commit) || $this->deployedAt === null) {
            return;
        }
        $projects = Project::query()->whereNotNull('repository_url')->get(['id', 'repository_url'])
            ->filter(static fn (Project $project): bool => self::same(GitHubRepository::fromOrigin((string) $project->repository_url), $repository));
        $project = $projects->count() === 1 ? $projects->first() : null;
        if (! $project instanceof Project) {
            Log::warning('No single Project has the Gateway release repository, so the release stamps no task group.', ['repository' => $this->repository, 'projects' => $projects->count()]);

            return;
        }
        $this->stamp($github, $access, $project->id, $repository, $this->commit, CarbonImmutable::parse($this->deployedAt));
    }

    /** Stamps the Project's unstamped merged groups that `$deployed`, deployed at `$at`, carries. */
    private function stamp(GitHubApi $github, RepositoryPullRequestAccess $access, int $projectId, GitHubRepository $repository, string $deployed, CarbonImmutable $at): void
    {
        $candidates = Task::topLevel()->where('project_id', $projectId)
            ->whereNotNull('merged_at')->whereNull('deployed_at')
            ->where('merged_at', '>=', $at->subDays(self::Days))->where('merged_at', '<=', $at)
            ->orderBy('merged_at')->orderBy('id')->limit(self::Limit)->get(['id', 'pr_url', 'watched_pr_url', 'merged_sha']);
        foreach ($candidates as $group) {
            try {
                $sha = $this->mergeCommit($github, $access, $repository, $group);
                $carried = $sha !== null && $this->contains($github, $access, $repository, $sha, $deployed);
            } catch (GitHubApiException $exception) {
                Log::warning('Could not read whether a deploy carried a task group.', ['task_group_id' => $group->id, 'deployment_id' => $this->deploymentId,
                    'commit' => $deployed, 'error' => $exception->getMessage()]);

                continue;
            }
            if ($carried) {
                // `updated_at` moves too, so the next task insights refresh records the new delivery and deploy wait.
                Task::topLevel()->whereKey($group->id)->whereNull('deployed_at')->toBase()->update(['deployed_at' => $at, 'updated_at' => now()]);
            }
        }
    }

    private static function same(?GitHubRepository $a, GitHubRepository $b): bool
    {
        return $a !== null && strcasecmp($a->owner, $b->owner) === 0 && strcasecmp($a->name, $b->name) === 0;
    }

    /** A succeeded production deploy, not a rollback, with its commit recorded. */
    private static function stamps(InstanceDeployment $deployment): bool
    {
        return $deployment->kind === 'deploy' && $deployment->status === 'succeeded'
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
