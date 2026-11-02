<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\TaskVms\ImageReaper;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmModelAdmission;
use App\Models\Operation;
use Illuminate\Support\ServiceProvider;

final class TaskVmsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Operation::updated(static fn (Operation $operation) => app(TaskVmImageProjection::class)->changed($operation));
        // An image build started or ended: republish the reaper's protected build ids after commit.
        Operation::saved(static function (Operation $operation): void {
            if ($operation->kind === 'task_vm.image_build' && ($operation->wasRecentlyCreated || $operation->wasChanged('status'))) {
                app(ImageReaper::class)->invalidate();
            }
        });
    }

    public function register(): void
    {
        $this->app->scoped(TaskVmModelAdmission::class);
    }
}
