<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\OperationIdempotency;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->scoped(OperationIdempotency::class);
    }
}
