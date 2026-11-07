<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\BuildTaskVmFleetCommand;
use App\Console\Commands\ConvergeTaskVmCommand;
use App\Console\Commands\ListTaskVmImageBuildsCommand;
use App\Console\Commands\PrepareTaskVmHostCommand;
use App\Console\Commands\PrepareTaskVmHubCommand;
use App\Console\Commands\RefreshTaskVmImageCommand;
use App\Console\Commands\RefreshTaskVmImagesCommand;
use App\Console\Commands\ResumeTaskVmCommand;
use App\Console\Commands\SaveTaskVmReadyCopiesCommand;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

final class TaskVmServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Read Node-owned host intent on first use, once per request or queued job.
        $this->app->scoped(TaskVmSettings::class, static fn (): TaskVmSettings => new ReflectionClass(TaskVmSettings::class)
            ->newLazyProxy(static fn (): TaskVmSettings => TaskVmSettings::fromConfig()));
        $this->app->bind(TaskVmProvider::class, IncusTaskVmProvider::class);
        // Scoped, so each queued job starts with a stopped clock and no baked programs.
        $this->app->scoped(TaskVmStepClock::class);
    }

    public function boot(): void
    {
        $this->commands([
            BuildTaskVmFleetCommand::class,
            ConvergeTaskVmCommand::class,
            ListTaskVmImageBuildsCommand::class,
            PrepareTaskVmHostCommand::class,
            PrepareTaskVmHubCommand::class,
            RefreshTaskVmImageCommand::class,
            RefreshTaskVmImagesCommand::class,
            ResumeTaskVmCommand::class,
            SaveTaskVmReadyCopiesCommand::class,
        ]);
    }
}
