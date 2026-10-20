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
use App\Domain\TaskVms\TaskVmSettings;
use Illuminate\Support\ServiceProvider;

final class TaskVmServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Refresh Node-owned host intent for every request and queued job.
        $this->app->scoped(TaskVmSettings::class, static fn (): TaskVmSettings => TaskVmSettings::fromConfig());
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
