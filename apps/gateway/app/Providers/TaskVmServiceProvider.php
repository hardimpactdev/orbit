<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\TaskVms\TaskVmStepClock;
use Illuminate\Support\ServiceProvider;

final class TaskVmServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Scoped, so each queued job starts with a stopped clock and no baked programs.
        $this->app->scoped(TaskVmStepClock::class);
    }
}
