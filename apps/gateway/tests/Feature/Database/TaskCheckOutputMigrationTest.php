<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('redacts and bounds stored check output and command evidence before API reads', function (): void {
    $default = DB::getDefaultConnection();
    config(['database.connections.check_output_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('check_output_migration');

    try {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], static fn (string $path): bool => basename($path) < '2026_11_17_000000_redact_stored_task_check_output.php'));
        Artisan::call('migrate', ['--database' => 'check_output_migration', '--path' => $paths, '--realpath' => true, '--force' => true]);
        DB::table('projects')->insert(['id' => 1, 'name' => 'Migration', 'slug' => 'migration', 'code' => 'MIG', 'type' => 'laravel-app', 'repository_url' => 'https://example.test/orbit.git', 'repository_identity' => 'example.test/orbit']);
        DB::table('tasks')->insert(['id' => 1, 'project_id' => 1, 'title' => 'Stored check', 'brief' => 'Before redaction.', 'status' => 'todo']);
        $raw = "Early line\n".str_repeat("ordinary output\n", 2000).'TOKEN='.str_repeat('s', 40_000)."\nFAILED last line\n";
        $evidence = ['start' => 'before', 'commands' => ['probe' => ['output' => $raw, 'base_output' => $raw, 'passed' => false]]];
        foreach ([$raw, null] as $index => $text) {
            DB::table('task_checks')->insert(['id' => $index + 1, 'task_id' => 1, 'kind' => 'handoff', 'status' => 'failed', 'pid' => 1, 'process_started' => 'migration-fixture', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => '2026-10-01 12:00:00', 'output' => $text, 'deliverable_evidence' => $index === 0 ? json_encode($evidence, JSON_THROW_ON_ERROR) : null, 'updated_at' => '2026-10-01 12:00:00']);
        }
        $migration = require database_path('migrations/2026_11_17_000000_redact_stored_task_check_output.php');
        $migration->up();
        $check = DB::table('task_checks')->where('id', 1)->sole();
        $stored = json_decode($check->deliverable_evidence, true, flags: JSON_THROW_ON_ERROR);
        foreach ([$check->output, $stored['commands']['probe']['output'], $stored['commands']['probe']['base_output']] as $text) {
            expect($text)->toContain('TOKEN=[REDACTED]', "FAILED last line\n")
                ->not->toContain('ssss')->not->toContain('Early line')
                ->and(strlen($text))->toBeLessThanOrEqual(16 * 1024);
        }
        expect($stored['start'])->toBe('before')
            ->and($stored['commands']['probe']['passed'])->toBeFalse()
            ->and($check->updated_at)->toBe('2026-10-01 12:00:00')
            ->and(DB::table('task_checks')->where('id', 2)->sole()->output)->toBeNull();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('check_output_migration');
    }
});
