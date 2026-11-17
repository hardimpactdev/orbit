<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\FileProblemsCommand;
use App\Console\Commands\TaskCopySettleCommand;
use App\Console\Commands\TaskMergeRoundCommand;
use App\Console\Commands\TaskPublishWhenDoneCommand;
use App\Console\Commands\TaskPullRequestGateCommand;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Tasks\BriefCoverageLabeler;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\SwampRunReader;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Tasks\GitHubTaskPullRequestCheckOutput;
use App\Infrastructure\Tasks\GitHubTaskPullRequestMerger;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Infrastructure\Tasks\JevBriefCoverageLabeler;
use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;
use App\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/** The tasks extension. Its code is still in core ([Extensions](/reference/extensions#the-extensions)). */
final class TasksServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        DeliverablePathRepository::class => NativeDeliverablePathRepository::class,
        TaskBriefCoverage::class => LaravelAiTaskBriefCoverage::class,
        BriefCoverageLabeler::class => JevBriefCoverageLabeler::class,
        TaskPullRequestPublisher::class => GitHubTaskPullRequestPublisher::class,
        TaskPullRequestCheckOutput::class => GitHubTaskPullRequestCheckOutput::class,
        TaskPullRequestMerger::class => GitHubTaskPullRequestMerger::class,
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

    /** Task containers reach models through the ai-router guard and their Node's forwarder. */
    #[\Override]
    public function dependencies(): array
    {
        return ['ai-router'];
    }

    /** Tasks requires the model guard before containers can use their placeholder key. */
    public function enable(): void
    {
        $this->app->make(ContainerModelRoute::class)->assertReady();
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

        $this->app->singleton(TaskExecutionLock::class, static fn (): TaskExecutionLock => new NativeTaskExecutionLock(
            rtrim(Config::string('orbit.home'), '/').'/locks/task-execution',
        ));
        $this->app->scoped(TaskBroadcasts::class);
    }

    public function boot(): void
    {
        $this->commands([
            FileProblemsCommand::class,
            TaskPullRequestGateCommand::class,
            TaskPublishWhenDoneCommand::class,
            TaskMergeRoundCommand::class,
            TaskCopySettleCommand::class,
        ]);

        // A canonical member selects either level. Internal Task queries keep their child scope.
        Route::bind('task', static function (string $value): Task {
            $task = Task::withoutGlobalScope('subtask')->whereKey($value)->first();

            if (! $task instanceof Task) {
                throw (new ModelNotFoundException)->setModel(Task::class, [$value]);
            }

            return $task;
        });

        // One notice per changed record, when the request or command that changed it ends (ADR 0151).
        $this->app->terminating(static function (): void {
            $app = app();
            if ($app->resolved(TaskBroadcasts::class)) {
                $app->make(TaskBroadcasts::class)->flush();
            }
        });
    }
}
