<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Schedules\ScheduleOperationSecrets;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Infrastructure\Schedules\SshScheduleRuntimeAccountResolver;
use Illuminate\Support\ServiceProvider;

final class SchedulesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ScheduleRuntimeAccountResolver::class => SshScheduleRuntimeAccountResolver::class,
        ScheduleRuntimeManager::class => RemoteScheduleRuntimeManager::class,
    ];

    public function register(): void
    {
        $this->app->bind('operation.secret.schedule.command', ScheduleOperationSecrets::class);
    }
}
