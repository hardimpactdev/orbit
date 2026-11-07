<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\TaskVms\TaskVmModelAdmission;
use Illuminate\Support\ServiceProvider;

final class TaskVmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TaskVmModelAdmission::class);
    }
}
