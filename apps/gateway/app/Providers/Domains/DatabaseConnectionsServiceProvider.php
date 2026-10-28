<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Activity\ActivityInputRedaction;
use App\Domain\DatabaseConnections\DatabaseActivityRedaction;
use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Infrastructure\DatabaseConnections\RegisteredDatabaseInspectionExecutor;
use Illuminate\Support\ServiceProvider;

final class DatabaseConnectionsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        DatabaseInspectionExecutor::class => RegisteredDatabaseInspectionExecutor::class,
    ];

    public function register(): void
    {
        $this->app->tag([DatabaseActivityRedaction::class], ActivityInputRedaction::class);
    }
}
