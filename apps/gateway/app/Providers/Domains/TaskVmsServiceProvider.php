<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\TaskVms\ScheduledTaskVmReaper;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmModelAdmission;
use App\Infrastructure\TaskVms\TaskVmImageArtifactSource;
use App\Infrastructure\TaskVms\TaskVmImageBootstrapStore;
use App\Infrastructure\TaskVms\TaskVmSecretSource;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TaskVmsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Operation::updated(static fn (Operation $operation) => app(TaskVmImageProjection::class)->changed($operation));
        foreach ([TaskVm::class, Task::class, TaskCheck::class, Operation::class, Activity::class, Project::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                Event::listen('eloquent.'.$event.': '.$model, static function ($record): void {
                    if ($record instanceof Operation && ! in_array($record->kind, ['tasks.turn', 'tasks.check', 'tasks.cancel', 'task_vm.destroy'], true)) {
                        return;
                    }
                    if ($record instanceof Activity && ! in_array($record->description, ['assistance requested', 'assistance cleared'], true)) {
                        return;
                    }
                    app(ScheduledTaskVmReaper::class)->invalidate();
                });
            }
        }
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
