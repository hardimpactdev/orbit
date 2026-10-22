<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

trait WithDocumentDeletionFixture
{
    use WithCommittedSqliteDatabase;

    public string $deletionHome;

    public ?Process $deletionServer = null;

    public function initializeDocumentDeletionFixture(): void
    {
        $this->deletionHome = sys_get_temp_dir().'/orbit-document-deletion-'.Str::uuid();
        mkdir($this->deletionHome, 0o700);
        config(['orbit.home' => $this->deletionHome, 'documents.cleanup_runtime' => $this->deletionHome.'/runtime']);
        $this->app->useStoragePath($this->deletionHome.'/storage');
        $this->useCommittedSqliteDatabase($this->deletionHome.'/database.sqlite');
        $this->beforeApplicationDestroyed(function (): void {
            $this->deletionServer?->stop();
            DB::disconnect();
            File::deleteDirectory($this->deletionHome);
        });
    }
}
