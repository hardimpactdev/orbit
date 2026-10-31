<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Activity\ActivityInputRedaction;
use App\Domain\DatabaseConnections\DatabaseActivityRedaction;
use Illuminate\Support\ServiceProvider;

final class DatabaseConnectionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([DatabaseActivityRedaction::class], ActivityInputRedaction::class);
    }
}
