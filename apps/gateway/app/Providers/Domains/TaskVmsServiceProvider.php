<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmModelAdmission;
use App\Infrastructure\TaskVms\TaskVmImageArtifactSource;
use App\Infrastructure\TaskVms\TaskVmImageBootstrapStore;
use App\Infrastructure\TaskVms\TaskVmSecretSource;
use App\Models\Operation;
use Illuminate\Support\ServiceProvider;

final class TaskVmsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Operation::updated(static fn (Operation $operation) => app(TaskVmImageProjection::class)->changed($operation));
    }

    public function register(): void
    {
        $this->app->scoped(TaskVmModelAdmission::class);
        $this->app->tag(TaskVmImageArtifactSource::class, OperationSecretSource::class);
        $this->app->tag(TaskVmImageBootstrapStore::class, OperationSecretOutputStore::class);
        foreach (['upcloud.api', 'task_vm.package_cache_github', 'task_vm.image_bootstrap'] as $source) {
            $name = 'task-vm.secret.'.$source;
            $this->app->bind($name, static fn (): TaskVmSecretSource => new TaskVmSecretSource($source));
            $this->app->tag($name, OperationSecretSource::class);
        }
    }
}
