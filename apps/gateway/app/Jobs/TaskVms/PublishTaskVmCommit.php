<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Pushes a task VM's approved commit from the Gateway's mirror ([Publish from the VM](/reference/compute-drivers#publish-from-the-vm)).
 * The task tick queues it and reads the result from the task VM row, so a VM that sends its pack slowly holds up no
 * group. It runs on the `tasks` queue, beside other jobs; the mirror lock keeps one push per Project. The job acts only while the row still
 * names its commit and branch as queued, so a repeated or late job changes nothing. A failure is recorded on the row,
 * and the tick's backoff decides when to queue the push again.
 */
final class PublishTaskVmCommit implements ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    /** Below the queue's `retry_after` of 1800 seconds. */
    public int $timeout = 1700;

    public bool $failOnTimeout = true;

    /** The Git commands' own budget, which ends before the job's timeout so that the job records its own failure. */
    private const int BudgetSeconds = 1600;

    public function __construct(public int $taskVmId, public string $commit, public string $branch)
    {
        $this->onTaskVmQueue(GatewayQueue::Tasks);
    }

    public function handle(GitHubTaskPullRequestPublisher $publisher): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm instanceof TaskVm) {
            $publisher->publishQueued($vm, $this->commit, $this->branch, microtime(true) + self::BudgetSeconds);
        }
    }

    /** A job that stopped or threw records the failure, so the tick reports it at once. */
    public function failed(?Throwable $exception): void
    {
        TaskVm::query()->whereKey($this->taskVmId)->where('publish_commit', $this->commit)->where('publish_branch', $this->branch)
            ->where('publish_state', TaskVmPublishState::Queued)
            ->update(['publish_state' => TaskVmPublishState::Failed, 'publish_error' => 'The task branch could not be pushed: '.($exception instanceof Throwable ? TaskVmException::messageOf($exception) : 'the publish job stopped.')]);
    }
}
