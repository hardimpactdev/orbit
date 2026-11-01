<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Projects\ScheduledProjectMaintenance;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([Project::class, Task::class, Operation::class] as $model) {
            foreach (['saved', 'deleted'] as $event) {
                Event::listen('eloquent.'.$event.': '.$model, static function ($record): void {
                    if (! $record instanceof Operation || in_array($record->kind, ['project.detect', 'project.routine', 'project.observe'], true)) {
                        app(ScheduledProjectMaintenance::class)->invalidate();
                    }
                });
            }
        }
    }

    /** @var array<class-string, class-string> */
    public array $bindings = [
        ProjectUpdateSourceMutator::class => RemoteProjectUpdateSourceMutator::class,
        ProjectUpdateProjectionMutator::class => NativeProjectUpdateProjectionMutator::class,
    ];
}
