<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationKinds::class);
        $this->app->scoped(OperationIdempotency::class);
    }
}
