<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubBranchPullRequest;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Domain\TaskVms\TaskVmPublishStop;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use LogicException;

/** Publishes the handoff's recorded commit through the existing approval push job. */
final readonly class TaskPublishOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(
        private TaskExecutionLock $execution,
        private TaskWorkspaceLease $leases,
        private TaskVmWorkspace $vms,
        private TaskCheckRunner $checks,
        private TaskPullRequestPublisher $publisher,
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private TaskVmPublishStop $pushStop,
        private TaskCheckedCandidate $candidates,
    ) {}

    public function requireCheck(Task $group, int $checkId): string
    {
        if (! $group->taskable instanceof Instance) {
            throw new ResourceOperationException('tasks.no_workspace', 'This task group has no workspace.', 409);
        }
        $check = TaskCheck::query()->whereKey($checkId)->whereHas('task', fn ($tasks) => $tasks->where('parent_id', $group->id))->first();
        if (! $check instanceof TaskCheck || $check->commit_sha === null || ! $this->candidates->eligible($group, $check)) {
            throw new ResourceOperationException('tasks.commit_not_checked', 'Publish requires a passed handoff check of this group with its recorded commit.', 422);
        }
        if (TaskVm::forGroup($group)?->state === TaskVmState::Ready) {
            $this->requireHead($group->taskable, $check->commit_sha);
        }

        return $check->commit_sha;
    }

    private function requireHead(Instance $instance, string $commit): void
    {
        if ($this->checks->snapshot($instance)->head !== $commit) {
            throw new ResourceOperationException('tasks.head_moved', 'The workspace HEAD is no longer the checked commit.', 409);
        }
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('workspace_ready', true), new OperationStepPlan('push', false), new OperationStepPlan('pull_request', true)]);
        $run->step('workspace_ready', fn (): mixed => $this->read($run));
        $commit = $run->step('push', fn (): mixed => $this->read($run));
        $pull = $run->step('pull_request', fn (): ?array => $this->pullRequest($run));

        if (! is_string($commit) || ($pull !== null && ! is_array($pull))) {
            throw new LogicException('Publish steps must record their commit and optional pull request.');
        }

        return OperationOutcome::succeeded([
            'branch' => 'task-'.$operation->target_id,
            'commit' => $commit,
            'pull_request_number' => $pull['number'] ?? null,
            'pull_request_url' => $pull['url'] ?? null,
        ]);
    }

    public function waitingStep(Operation $operation, string $name): OperationWaitingStep
    {
        return $this;
    }

    public function read(OperationRun $run): mixed
    {
        try {
            return $this->execution->synchronized($run->operation()->target_id, function () use ($run): mixed {
                $operation = $run->operation();
                $group = Task::topLevel()->with('taskable', 'project')->findOrFail($operation->target_id);
                if ($operation->current_step === 'workspace_ready') {
                    try {
                        $this->vms->node($group);
                    } catch (TaskCapacityException) {
                        $run->wait(10);
                    }

                    return null;
                }
                if (! ($operation->state['publish_queued'] ?? false)) {
                    $this->leases->run($group, 'publication', 'operation:'.$operation->id, function () use ($run, $group, $operation): void {
                        $input = StartTaskPublishData::from($operation->input);
                        $commit = $this->requireCheck($group, $input->check);
                        $run->remember('commit', fn (): string => $commit);
                        $run->remember('publish_queued', function () use ($group, $commit): bool {
                            try {
                                $this->publisher->push($group, $commit);
                            } catch (TaskPullRequestException $exception) {
                                if (! $exception->pending) {
                                    throw new ResourceOperationException($exception->errorCode, $exception->getMessage(), previous: $exception);
                                }
                            }

                            return true;
                        });
                    });
                }
                $vm = $this->publishRecord($operation);
                if ($vm->publish_state === TaskVmPublishState::Queued) {
                    $run->wait(10);
                }
                if ($vm->publish_state !== TaskVmPublishState::Pushed) {
                    throw new ResourceOperationException($vm->publish_error_code ?? 'tasks.push_failed', $vm->publish_error ?? 'The task publish job failed.');
                }

                return $vm->publish_commit;
            });
        } catch (TaskWorkspaceWaiting) {
            $run->wait(10);
        } catch (TaskVmFailedException $exception) {
            throw new ResourceOperationException($exception->vm->error_code ?? 'task_vm.job_failed', $exception->getMessage(), previous: $exception);
        }
    }

    private function publishRecord(Operation $operation): TaskVm
    {
        $commit = $operation->state['commit'] ?? null;
        if (! is_string($commit)) {
            throw new LogicException('A queued publish must record its commit.');
        }

        return TaskVm::query()->where('group_id', $operation->target_id)->where('publish_commit', $commit)
            ->where('publish_branch', 'task-'.$operation->target_id)->first()
            ?? throw new ResourceOperationException('tasks.push_failed', 'The task publish job record is unavailable.');
    }

    /** @return array{number: int, url: string}|null */
    private function pullRequest(OperationRun $run): ?array
    {
        $operation = $run->operation();
        $input = StartTaskPublishData::from($operation->input)->pull_request;
        if ($input === null) {
            return null;
        }
        $group = Task::topLevel()->with('project')->findOrFail($operation->target_id);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $base = $group->project->default_branch;
        if (! $repository instanceof GitHubRepository || ! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new ResourceOperationException('tasks.publish_failed', 'The Project must have a GitHub repository and a valid default branch.');
        }
        try {
            $token = $this->access->token($repository);
            $branch = 'task-'.$group->id;
            $open = array_find($this->github->pullRequestsByHead($token, $repository, $branch), static fn (GitHubBranchPullRequest $pull): bool => $pull->state === GitHubPullRequestState::Open);
            if ($open !== null) {
                $this->github->updatePullRequest($token, $repository, $open->number, $input['title'], $input['body']);
                $number = $open->number;
                $url = $open->url;
            } else {
                $opened = $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($branch, $base, $input['title'], $input['body']));
                $number = $opened->number ?? $repository->pullRequestNumber($opened->url);
                $url = $opened->url;
                if ($number === null) {
                    throw new ResourceOperationException('tasks.publish_failed', 'GitHub did not return the pull request number.');
                }
            }
            $group->update(['pr_url' => $url, 'watched_pr_number' => $number, 'watched_pr_url' => $url, 'watched_pr_state' => 'open']);

            return ['number' => $number, 'url' => $url];
        } catch (GitHubApiException $exception) {
            throw new ResourceOperationException('tasks.publish_failed', $exception->getMessage(), previous: $exception);
        }
    }

    public function stop(OperationRun $run): void
    {
        $operation = $run->operation();
        if (! ($operation->state['publish_queued'] ?? false)) {
            return;
        }
        $vm = $this->publishRecord($operation);
        $this->pushStop->stop(Task::topLevel()->findOrFail($operation->target_id), (string) $vm->publish_commit, (string) $vm->publish_branch);
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $group = Task::topLevel()->find($operation->target_id);
        if ($group instanceof Task) {
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
        }

        return $failure;
    }
}
