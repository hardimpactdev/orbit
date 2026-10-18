<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('classifies only open ordinary failures with stored removal reasons before runtime reads the kind', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.removal_assistance' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('removal_assistance');

    try {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], static fn (string $path): bool => basename($path) < '2026_11_16_000000_classify_task_removal_failures.php'));
        Artisan::call('migrate', ['--database' => 'removal_assistance', '--path' => $paths, '--realpath' => true, '--force' => true]);
        DB::table('projects')->insert(['id' => 1, 'name' => 'Migration', 'slug' => 'migration', 'code' => 'MIG', 'type' => 'laravel-app', 'repository_url' => 'https://example.test/orbit.git', 'repository_identity' => 'example.test/orbit']);
        $rows = [
            [true, 'failure', 'Workspace removal failed: disk full', 'removal_failure'],
            [true, 'failure', 'Merged pull request cleanup failed: disk full', 'removal_failure'],
            [true, 'failure', 'Workspace ownership refused: wrong owner', 'removal_failure'],
            [false, 'failure', 'Workspace removal failed: historical', 'failure'],
            [true, 'direction', 'Workspace removal failed: which mirror?', 'direction'],
            [true, 'failure', 'A check failed.', 'failure'],
            [true, 'failure', 'Details: Workspace removal failed: not a prefix', 'failure'],
            [true, 'removal_failure', 'Already typed.', 'removal_failure'],
        ];
        foreach ($rows as $index => [$requested, $kind, $reason]) {
            DB::table('tasks')->insert(['id' => $index + 1, 'project_id' => 1, 'title' => 'Stored task '.$index, 'brief' => 'Before classification.', 'status' => 'settling',
                'assistance_requested' => $requested, 'assistance_kind' => $kind, 'assistance_question' => $kind === 'direction' ? 'Which mirror?' : null, 'assistance_reason' => $reason, 'updated_at' => '2026-10-01 12:00:00']);
        }

        $migration = require database_path('migrations/2026_11_16_000000_classify_task_removal_failures.php');
        $migration->up();
        $migration->up();

        foreach ($rows as $index => [$requested, , $reason, $expected]) {
            $row = DB::table('tasks')->where('id', $index + 1)->sole();
            expect($row->assistance_kind)->toBe($expected)
                ->and((bool) $row->assistance_requested)->toBe($requested)
                ->and($row->assistance_reason)->toBe($reason)
                ->and($row->updated_at)->toBe('2026-10-01 12:00:00');
        }
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('removal_assistance');
    }
});
