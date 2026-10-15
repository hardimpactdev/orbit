<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmProviders;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use Illuminate\Support\ServiceProvider;

final class TaskVmServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Refresh Node-owned host intent for every request and queued job.
        $this->app->scoped(TaskVmSettings::class, static fn (): TaskVmSettings => TaskVmSettings::fromConfig());
        $this->app->bind(TaskVmProvider::class, TaskVmProviders::class);
        // Scoped, so each queued job starts with a stopped clock and no baked programs.
        $this->app->scoped(TaskVmStepClock::class);
    }
}
