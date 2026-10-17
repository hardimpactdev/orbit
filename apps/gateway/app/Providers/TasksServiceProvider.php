<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\Tasks\TaskWorkspaceOperationRecovery;
use App\Domain\Tasks\TaskWorkspaceRoute;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Infrastructure\Tasks\AgentViewTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Infrastructure\Tasks\GitHubTaskPullRequestMerger;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Infrastructure\Tasks\JevBriefCoverageLabeler;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\RemoteTaskReviewDiff;
use App\Infrastructure\Tasks\RemoteTaskTurnReceipts;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceMcp;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceStateReader;
use App\Infrastructure\Tasks\RemoteWorkspaceOperationJournal;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Models\Task;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/** The tasks extension. Its code is still in core ([Extensions](/reference/extensions#the-extensions)). */
final class TasksServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceProvisioning::class => TaskWorkspaceProvisioner::class,
        TaskWorkspaceRoute::class => TaskWorkspaceProvisioner::class,
        TaskTopologyAdmission::class => TaskVmTopologyAdmission::class,
        AgentSpawner::class => TaskAgentSpawner::class,
        TaskWorkspaceMcp::class => RemoteTaskWorkspaceMcp::class,
        TaskWorkspaceSigner::class => RemoteTaskWorkspaceSigner::class,
        TaskWorkspaceDiffReader::class => AgentViewTaskWorkspaceDiffReader::class,
        TaskReviewDiff::class => RemoteTaskReviewDiff::class,
        TaskWorkspaceStateReader::class => RemoteTaskWorkspaceStateReader::class,
        TaskTurnReceipts::class => RemoteTaskTurnReceipts::class,
        TaskCheckRunner::class => RemoteTaskCheckRunner::class,
        DeliverablePathRepository::class => NativeDeliverablePathRepository::class,
        TaskBriefCoverage::class => LaravelAiTaskBriefCoverage::class,
        BriefCoverageLabeler::class => JevBriefCoverageLabeler::class,
        TaskPullRequestPublisher::class => GitHubTaskPullRequestPublisher::class,
        TaskPullRequestMerger::class => GitHubTaskPullRequestMerger::class,
        TaskBaseBranchFetcher::class => GitHubTaskBaseBranchFetcher::class,
        TaskSettleMetricsCollector::class => LocalTaskSettleMetricsCollector::class,
        CoderSettleNotifier::class => HttpCoderSettleNotifier::class,
        TaskPullRequestWatcher::class => HttpTaskPullRequestWatcher::class,
        TaskPullRequestUpdater::class => HttpTaskPullRequestWatcher::class,
        TaskPullRequestReviewWatcher::class => HttpTaskPullRequestWatcher::class,
    ];

    #[\Override]
    public function name(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Runs planned work with coding agents and delivers it as a pull request.';
    }

    /** Pi on a task VM reaches models only through the proxycli CLIProxyAPI. */
    #[\Override]
    public function dependencies(): array
    {
        return ['proxycli'];
    }

    /** A task VM writes its model key before the router registers it, so Tasks starts only once the router can revoke keys. */
    public function enable(): void
    {
        $this->app->make(ModelKeyIssuer::class)->assertReady();
        $this->app->make(TaskBroadcasts::class)->extensionChanged(true);
    }

    public function disable(): void
    {
        $this->app->make(TaskBroadcasts::class)->extensionChanged(false);
    }

    public function register(): void
    {
        $this->app->bind(TaskWorkspaceOperationRecovery::class, RemoteWorkspaceOperationJournal::class);
        parent::register();

        $this->app->bind(AgentDriverRegistry::class, fn (Application $app): AgentDriverRegistry => new AgentDriverRegistry([$app->make(PiDriver::class)]));
        $this->app->bind(TaskReviewPacketBuilder::class, fn (Application $app): TaskReviewPacketBuilder => new TaskReviewPacketBuilder($app->make(TaskReviewDiff::class)));
        $this->app->singleton(TaskExecutionLock::class, static fn (): TaskExecutionLock => new NativeTaskExecutionLock(
            rtrim(Config::string('orbit.home'), '/').'/locks/task-execution',
        ));
        $this->app->singleton(TaskBroadcasts::class);
        $this->app->singleton(TaskTurnFetchNotice::class);
    }

    public function boot(): void
    {
        // Observers are declared on the models with ObservedBy. Calling observe() here would load those
        // models during every boot, so test impact analysis would rerun unrelated tests after a model edit.

        // {group} is the top-level task. The Task scope itself returns only subtasks.
        Route::bind('group', static function (string $value): Task {
            $task = Task::topLevel()->whereKey($value)->first();

            if (! $task instanceof Task) {
                throw (new ModelNotFoundException)->setModel(Task::class, [$value]);
            }

            return $task;
        });

        // One notice per changed record, when the request or command that changed it ends (ADR 0151).
        $this->app->terminating(function (): void {
            if ($this->app->resolved(TaskBroadcasts::class)) {
                $this->app->make(TaskBroadcasts::class)->flush();
            }
        });
    }
}
