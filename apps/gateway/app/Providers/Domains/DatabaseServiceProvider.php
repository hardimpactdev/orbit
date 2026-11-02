<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Infrastructure\Database\LongWriteTransactionLog;
use Illuminate\Support\ServiceProvider;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(LongWriteTransactionLog::class)->register();
    }
}
