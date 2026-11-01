<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Observation\ObservationQueueRedisSource;
use App\Domain\Operations\OperationSecretSource;
use Illuminate\Support\ServiceProvider;

final class DoctorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ObservationQueueRedisSource::class], OperationSecretSource::class);
    }
}
