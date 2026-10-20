<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\BuildTaskVmImageCommand;
use App\Console\Commands\BuildTaskVmRecipeCommand;
use App\Console\Commands\BuildUpCloudTaskVmImageCommand;
use App\Console\Commands\ListTaskVmImageBuildsCommand;
use App\Console\Commands\PackageCacheUsageCommand;
use App\Console\Commands\PrepareTaskVmHostCommand;
use App\Console\Commands\PrepareTaskVmHubCommand;
use App\Domain\TaskVms\TaskVmPackageCacheMount;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmPackageCache;
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
        $this->app->bind(TaskVmPackageCacheMount::class, TaskVmPackageCache::class);
        // Scoped, so each queued job starts with a stopped clock and no baked programs.
        $this->app->scoped(TaskVmStepClock::class);
    }

    public function boot(): void
    {
        $this->commands([
            BuildTaskVmImageCommand::class,
            BuildTaskVmRecipeCommand::class,
            BuildUpCloudTaskVmImageCommand::class,
            ListTaskVmImageBuildsCommand::class,
            PackageCacheUsageCommand::class,
            PrepareTaskVmHostCommand::class,
            PrepareTaskVmHubCommand::class,
        ]);
    }
}
