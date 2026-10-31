<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\OperationSecretSource;
use App\Infrastructure\TaskVms\TaskVmImageArtifactSource;
use App\Infrastructure\TaskVms\TaskVmSecretSource;
use Illuminate\Support\ServiceProvider;

final class TaskVmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(TaskVmImageArtifactSource::class, OperationSecretSource::class);
        foreach (['upcloud.api', 'task_vm.model_key', 'task_vm.pi_token', 'task_vm.package_cache_github', 'task_vm.image_bootstrap'] as $source) {
            $name = 'task-vm.secret.'.$source;
            $this->app->bind($name, static fn (): TaskVmSecretSource => new TaskVmSecretSource($source));
            $this->app->tag($name, OperationSecretSource::class);
        }
    }
}
