<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Private, committed databases without invalidating RefreshDatabase's in-memory migration cache. */
trait WithCommittedSqliteDatabase
{
    /** @var list<string> */
    protected array $connectionsToTransact = ['sqlite'];

    private static ?string $committedSqliteTemplate = null;

    public function useCommittedSqliteDatabase(string $database): void
    {
        config([
            'database.connections.committed_test' => config()->array('database.connections.sqlite'),
            'queue.batching.database' => 'committed_test',
            'queue.failed.database' => 'committed_test',
        ]);
        DB::setDefaultConnection('committed_test');

        if (self::$committedSqliteTemplate === null) {
            $template = sys_get_temp_dir().'/committed-sqlite-'.bin2hex(random_bytes(16)).'.sqlite';
            touch($template);
            config(['database.connections.committed_test.database' => $template]);
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
            DB::disconnect('committed_test');
            self::$committedSqliteTemplate = $template;
        }

        // The template is never opened again. Only a case's private copy may receive writes.
        $this->assertTrue(copy(self::$committedSqliteTemplate, $database));
        config(['database.connections.committed_test.database' => $database]);
        DB::purge('committed_test');
    }
}
