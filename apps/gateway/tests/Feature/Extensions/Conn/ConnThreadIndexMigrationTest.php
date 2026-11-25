<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds and rolls back the host index without rewriting the existing Conn tables', function (): void {
    $connection = 'conn_thread_index_migration';
    config(['database.connections.'.$connection => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);

    try {
        app('migrator')->usingConnection($connection, function (): void {
            Schema::create('instances', fn (Blueprint $table) => $table->id());
            Schema::create('conn_environments', fn (Blueprint $table) => $table->id());
            (require database_path('migrations/2027_02_15_000000_create_conn_threads_table.php'))->up();
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER conn_threads_nonempty_title BEFORE UPDATE ON conn_threads
                WHEN NEW.title = '' BEGIN SELECT RAISE(ABORT, 'blank title'); END;
                SQL);
            DB::table('instances')->insert(['id' => 1]);
            DB::table('conn_environments')->insert(['id' => 1]);
            DB::table('conn_threads')->insert(['id' => 1, 'conn_environment_id' => 1, 'instance_id' => 1,
                'thread_id' => 'existing', 'title' => 'Existing container thread', 'seen_at' => now()]);

            $existingSchema = static fn (): array => DB::table('sqlite_master')->whereIn('tbl_name', ['conn_threads', 'conn_environments'])
                ->orderBy('name')->get()->map(static fn (stdClass $row): array => (array) $row)->all();
            $schemaBefore = $existingSchema();
            $rowBefore = (array) DB::table('conn_threads')->sole();
            $hostIndex = require database_path('migrations/2027_03_27_000000_add_conn_host_thread_classification.php');
            $watermarks = require database_path('migrations/2027_03_28_000000_add_conn_librarian_start_watermark.php');

            DB::enableQueryLog();
            $hostIndex->up();
            $watermarks->up();
            expect(Schema::hasTable('conn_host_threads'))->toBeTrue()
                ->and(Schema::hasTable('conn_thread_classifications'))->toBeTrue()
                ->and(Schema::hasTable('conn_librarian_watermarks'))->toBeTrue()
                ->and($existingSchema())->toBe($schemaBefore)
                ->and((array) DB::table('conn_threads')->sole())->toBe($rowBefore);

            $watermarks->down();
            $hostIndex->down();
            expect(Schema::hasTable('conn_host_threads'))->toBeFalse()
                ->and(Schema::hasTable('conn_thread_classifications'))->toBeFalse()
                ->and(Schema::hasTable('conn_librarian_watermarks'))->toBeFalse()
                ->and($existingSchema())->toBe($schemaBefore)
                ->and((array) DB::table('conn_threads')->sole())->toBe($rowBefore)
                ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
            expect(implode("\n", array_column(DB::getQueryLog(), 'query')))->not->toMatch('/\b(?:alter table|__temp__)/i');
        });
    } finally {
        DB::purge($connection);
    }
});
