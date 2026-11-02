<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Tasks\InstallSwampReaderCredentialAction;
use App\Domain\Tasks\ProofReaper;
use App\Domain\Tasks\SwampReaderAssistance;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A proof group or its VM changed: republish the proof reaper's inputs after commit.
        foreach (['saved', 'deleted'] as $event) {
            Event::listen('eloquent.'.$event.': '.Task::class, static function (Task $task): void {
                if ($task->parent_id === null && $task->project_id === ProofReaper::ProjectId) {
                    app(ProofReaper::class)->invalidate();
                }
            });
            Event::listen('eloquent.'.$event.': '.TaskVm::class, static function (TaskVm $vm): void {
                if ($vm->group_id !== null && Task::query()->whereKey($vm->group_id)->where('project_id', ProofReaper::ProjectId)->exists()) {
                    app(ProofReaper::class)->invalidate();
                }
            });
        }
    }

    public function register(): void
    {
        $this->app->bind(InstallSwampReaderCredentialAction::class, fn ($app): InstallSwampReaderCredentialAction => new InstallSwampReaderCredentialAction(
            $app->make(GatewayReleaseLock::class),
            $app->make(SwampReaderEnvironment::class),
            new StreamWebSocketClient(2_097_152, 2_097_152),
            $app->make(SwampReaderAssistance::class),
        ));
    }
}
