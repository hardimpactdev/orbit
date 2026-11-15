<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\ResumeTaskVmCommand;
use App\Domain\TaskVms\TaskVmSettings;
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
        // Scoped, so each queued job starts with a stopped clock and no baked programs.
        $this->app->scoped(TaskVmStepClock::class);
    }

    public function boot(): void
    {
        $this->commands([
            ResumeTaskVmCommand::class,
        ]);
    }
}
