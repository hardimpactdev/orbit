<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents;

use App\Domain\Activity\ActivityInputRedaction;
use App\Domain\Gateway\ExactRequestBody;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\GatewayReleases\GatewayReleaseCleanup;
use App\Domain\Projects\ProjectRemovalHook;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Orbit\Extensions\Documents\Console\Commands\InvalidateDocumentCleanup;
use Orbit\Extensions\Documents\Console\Commands\PauseDocumentCleanup;
use Orbit\Extensions\Documents\Console\Commands\ReconcileDocumentCleanup;
use Orbit\Extensions\Documents\Console\Commands\ReconcileDocumentProbes;
use Orbit\Extensions\Documents\Console\Commands\RepairDocumentProbeCredentials;
use Orbit\Extensions\Documents\Console\Commands\ResumeDocumentCleanup;
use Orbit\Extensions\Documents\Console\Commands\ShowDocumentCleanupStatus;
use Orbit\Extensions\Documents\Console\Commands\WorkDocumentCleanup;
use Orbit\Extensions\Documents\Infrastructure\CleanupGate;
use Orbit\Extensions\Documents\Infrastructure\CleanupSchedulerSession;
use Orbit\Extensions\Documents\Infrastructure\DocumentsActivityInputRedaction;
use Orbit\Extensions\Documents\Infrastructure\DocumentsExactRequestBody;
use Orbit\Extensions\Documents\Infrastructure\DocumentsFilesystem;
use Orbit\Extensions\Documents\Infrastructure\DocumentsProjectRemoval;
use Orbit\Extensions\Documents\Infrastructure\DocumentsReleaseCleanup;
use Orbit\Extensions\Documents\Infrastructure\DocumentsServiceStartHook;

/** The documents extension: Project folders and versioned files ([Project Documents](/reference/project-documents)). */
final class DocumentsServiceProvider extends ExtensionServiceProvider
{
    public function name(): string
    {
        return 'documents';
    }

    public function description(): string
    {
        return 'Project folders and versioned files in private S3 storage, with a plain-text editor.';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documents.php', 'documents');
        if (! $this->app->configurationIsCached()) {
            $this->app->make('config')->set('filesystems.disks.documents', config()->array('documents.disk'));
        }
        $this->contribute(ProjectRemovalHook::class, DocumentsProjectRemoval::class);
        $this->contribute(GatewayReleaseCleanup::class, DocumentsReleaseCleanup::class);
        $this->contribute(ActivityInputRedaction::class, DocumentsActivityInputRedaction::class);
        $this->contribute(GatewayServiceStartHook::class, DocumentsServiceStartHook::class);
        $this->contribute(ExactRequestBody::class, DocumentsExactRequestBody::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->commands([
            InvalidateDocumentCleanup::class,
            PauseDocumentCleanup::class,
            ReconcileDocumentCleanup::class,
            ReconcileDocumentProbes::class,
            RepairDocumentProbeCredentials::class,
            ResumeDocumentCleanup::class,
            ShowDocumentCleanupStatus::class,
            WorkDocumentCleanup::class,
        ]);
        // Document cleanup stays in the scheduler: its permit belongs to the scheduler's start. It runs while the
        // switch is off too, because it only finishes deletions that are already recorded.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('project-documents:probes:reconcile')->everyMinute()->withoutOverlapping(10);
            $schedule->command('project-documents:cleanup:work')->everyFiveMinutes()->withoutOverlapping(60);
        });

        Storage::extend('documents', fn (): AwsS3V3Adapter => app(DocumentsFilesystem::class)->current());
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command === 'schedule:run' && app(CleanupSchedulerSession::class)->isRecurringTick()) {
                return;
            }
            if (in_array($event->command, ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen'], true)) {
                app(CleanupGate::class)->invalidate();
            }
            if ($event->command === 'schedule:work') {
                app(CleanupSchedulerSession::class)->start();
            }
        });
        Event::listen(CommandFinished::class, static function (CommandFinished $event): void {
            if ($event->command === 'schedule:work') {
                app(CleanupSchedulerSession::class)->close();
            }
        });
    }
}
