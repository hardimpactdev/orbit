<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CollectProblemsCommand;
use App\Console\Commands\FileProblemsCommand;
use App\Console\Commands\JevReportCommand;
use App\Console\Commands\ReleaseTaskWorkspaceLeaseCommand;
use App\Console\Commands\RenderTaskPromptCommand;
use App\Console\Commands\TaskGitHubReviewsCommand;
use App\Console\Commands\TickTaskSessionsCommand;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Infrastructure\Tasks\GitHubTaskPullRequestMerger;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\NativeTaskExecutionLock;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\Task;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/** The tasks extension. Its code is still in core ([Extensions](/reference/extensions#the-extensions)). */
final class TasksServiceProvider extends ExtensionServiceProvider
{
    /**
     * The other Tasks interfaces declare their implementation with #[Bind]. These three move to #[Bind] after the
     * handoff CI change (#1399), which edits the same files.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        TaskCheckRunner::class => RemoteTaskCheckRunner::class,
        TaskPullRequestPublisher::class => GitHubTaskPullRequestPublisher::class,
        TaskPullRequestMerger::class => GitHubTaskPullRequestMerger::class,
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

    /** Pi on a task VM reaches models only through the ai-router CLIProxyAPI. */
    #[\Override]
    public function dependencies(): array
    {
        return ['ai-router'];
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
        parent::register();

        $this->app->bind(AgentDriverRegistry::class, fn (Application $app): AgentDriverRegistry => new AgentDriverRegistry([$app->make(PiDriver::class)]));
        $this->app->singleton(TaskExecutionLock::class, static fn (): TaskExecutionLock => new NativeTaskExecutionLock(
            rtrim(Config::string('orbit.home'), '/').'/locks/task-execution',
        ));
        // Constructors give these a default, so they stay bound here (see ApplicationServiceProvider).
        $this->app->singleton(TaskBroadcasts::class);
        $this->app->singleton(TaskTurnFetchNotice::class);
    }

    public function boot(): void
    {
        $this->commands([
            CollectProblemsCommand::class,
            FileProblemsCommand::class,
            JevReportCommand::class,
            ReleaseTaskWorkspaceLeaseCommand::class,
            RenderTaskPromptCommand::class,
            TaskGitHubReviewsCommand::class,
            TickTaskSessionsCommand::class,
        ]);
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
