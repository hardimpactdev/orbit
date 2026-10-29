<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Console\Commands\CheckOperationHealthCommand;
use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([CheckOperationHealthCommand::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'orbit:operations-check', uniqueFor: 120, overlapMinutes: 2)->everyMinute();
        });
    }

    public function register(): void
    {
        $this->app->singleton(OperationKinds::class);
        $this->app->scoped(OperationIdempotency::class);
    }
}
