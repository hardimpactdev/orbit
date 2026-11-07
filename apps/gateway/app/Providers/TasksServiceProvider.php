<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\FileProblemsCommand;
use App\Console\Commands\TaskPullRequestGateCommand;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\SwampRunReader;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStartCommit;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Tasks\AgentViewTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\GitHubTaskPullRequestCheckOutput;
use App\Infrastructure\Tasks\GitHubTaskPullRequestMerger;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\GitHubTaskStartCommit;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Infrastructure\Tasks\JevBriefCoverageLabeler;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceStateReader;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;
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
        TaskWorkspaceDiffReader::class => AgentViewTaskWorkspaceDiffReader::class,
        TaskWorkspaceStateReader::class => RemoteTaskWorkspaceStateReader::class,
        TaskCheckRunner::class => RemoteTaskCheckRunner::class,
        DeliverablePathRepository::class => NativeDeliverablePathRepository::class,
        TaskBriefCoverage::class => LaravelAiTaskBriefCoverage::class,
        BriefCoverageLabeler::class => JevBriefCoverageLabeler::class,
        TaskPullRequestPublisher::class => GitHubTaskPullRequestPublisher::class,
        TaskPullRequestCheckOutput::class => GitHubTaskPullRequestCheckOutput::class,
        TaskPullRequestMerger::class => GitHubTaskPullRequestMerger::class,
        TaskStartCommit::class => GitHubTaskStartCommit::class,
        TaskSettleMetricsCollector::class => LocalTaskSettleMetricsCollector::class,
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

    /** Pi on a task VM reaches models only through the ai-router task VM guard. */
    #[\Override]
    public function dependencies(): array
    {
        return ['ai-router'];
    }

    /** A task start writes the guard origin and the fixed model key into the VM, so Tasks starts only once the router has its guard. */
    public function enable(): void
    {
        $this->app->make(TaskVmModelRoute::class)->assertReady();
        $this->app->make(TaskBroadcasts::class)->extensionChanged(true);
    }

    public function disable(): void
    {
        $this->app->make(TaskBroadcasts::class)->extensionChanged(false);
    }

    public function register(): void
    {
        $this->app->bind(SwampRunReader::class, static fn (): SwampRunReader => new WebSocketSwampRunReader(
            new StreamWebSocketClient(2_097_152, 2_097_152),
            Config::string('swamp.address'),
            Config::string('swamp.read_token'),
            Config::string('swamp.ca_path'),
        ));

        parent::register();

        $this->app->bind(AgentDriverRegistry::class, fn (Application $app): AgentDriverRegistry => new AgentDriverRegistry([$app->make(PiDriver::class)]));
        $this->app->singleton(TaskExecutionLock::class, static fn (): TaskExecutionLock => new NativeTaskExecutionLock(
            rtrim(Config::string('orbit.home'), '/').'/locks/task-execution',
        ));
        $this->app->singleton(TaskBroadcasts::class);
        $this->app->singleton(TaskTurnFetchNotice::class);
    }

    public function boot(): void
    {
        $this->commands([
            FileProblemsCommand::class,
            TaskPullRequestGateCommand::class,
        ]);

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
