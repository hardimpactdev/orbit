<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('converts legacy episodes without changing their task, mute or other evidence', function (string $source, bool $dropHistory): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Existing group', 'brief' => 'Operator brief', 'status' => 'backlog']);
    $metadata = ['request_ids' => ['request-1'], 'summary' => 'Keep this', 'resolved_at' => '2026-11-01T12:00:00Z'];
    $id = DB::table('problem_fingerprints')->insertGetId([
        'fingerprint' => $source.'|legacy', 'source' => $source, 'occurrences' => 91,
        'first_seen' => '2026-10-01 10:00:00', 'last_seen' => '2026-10-01 10:12:00',
        'task_group_id' => $group->id, 'muted_until' => '2026-12-01 00:00:00', 'filed_at' => '2026-10-02 00:00:00',
        'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 00:00:00',
        'evidence' => json_encode([...$metadata, 'observation_times' => [
            '2026-10-01T10:12:00Z', '2026-10-01T12:01:00+02:00', 'invalid timestamp', '', null, 12, '2026-10-01T10:04:59Z',
        ]], JSON_THROW_ON_ERROR),
    ]);
    $before = (array) DB::table('problem_fingerprints')->find($id);
    $migration = require database_path('migrations/2027_03_07_000000_convert_legacy_problem_evidence.php');

    $migration->up();

    $after = (array) DB::table('problem_fingerprints')->find($id);
    $expected = $dropHistory
        ? [...$metadata, 'observation_counts' => [], 'counted_blocks' => []]
        : [...$metadata, 'observation_times' => ['2026-10-01T12:01:00+02:00', '2026-10-01T10:12:00Z'], 'observation_counts' => [2, 1], 'counted_blocks' => [5969496, 5969498]];
    expect(json_decode($after['evidence'], true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
    expect(array_diff_key($after, ['evidence' => true]))->toBe([
        ...array_diff_key($before, ['evidence' => true]),
        'occurrences' => $dropHistory ? 0 : 2,
        'first_seen' => $dropHistory ? null : $before['first_seen'],
        'last_seen' => $dropHistory ? null : $before['last_seen'],
    ]);

    $migration->up();
    $migration->down();
    expect((array) DB::table('problem_fingerprints')->find($id))->toBe($after);
    expect($group->refresh()->brief)->toBe('Operator brief');
})->with([
    'log discards collector times' => ['log', true],
    'activity discards collector times' => ['activity', true],
    'doctor keeps signal times' => ['doctor', false],
    'assistance keeps signal times' => ['assist', false],
    'release keeps signal times' => ['release', false],
    'swamp keeps signal times' => ['swamp', false],
]);

it('converts inactive and invalid histories across chunks without clearing other sources seen times', function (): void {
    for ($id = 1; $id <= 205; $id++) {
        DB::table('problem_fingerprints')->insert([
            'fingerprint' => 'assist|'.$id, 'source' => 'assist', 'occurrences' => 0,
            'first_seen' => '2026-10-01 10:00:00', 'last_seen' => '2026-10-01 10:00:00',
            'evidence' => $id % 2 === 0 ? '{"observation_times":["invalid timestamp"]}' : '{}',
        ]);
    }
    $migration = require database_path('migrations/2027_03_07_000000_convert_legacy_problem_evidence.php');

    $migration->up();

    expect(DB::table('problem_fingerprints')->count())->toBe(205);
    foreach (DB::table('problem_fingerprints')->get() as $row) {
        expect(json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR))->toBe(['observation_counts' => [], 'counted_blocks' => []]);
        expect($row->occurrences)->toBe(0);
        expect($row->first_seen)->toBe('2026-10-01 10:00:00');
        expect($row->last_seen)->toBe('2026-10-01 10:00:00');
    }
});

it('keeps all converted windows but only the latest twenty display samples', function (): void {
    $times = [];
    for ($minute = 0; $minute <= 100; $minute += 5) {
        $times[] = gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-01T10:00:00Z') + $minute * 60);
    }
    $id = DB::table('problem_fingerprints')->insertGetId(['fingerprint' => 'doctor|long', 'source' => 'doctor', 'occurrences' => 99,
        'evidence' => json_encode(['observation_times' => array_reverse($times)], JSON_THROW_ON_ERROR)]);

    (require database_path('migrations/2027_03_07_000000_convert_legacy_problem_evidence.php'))->up();

    $row = DB::table('problem_fingerprints')->find($id);
    expect($row->occurrences)->toBe(21);
    expect(json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'observation_times' => array_slice($times, 1), 'observation_counts' => array_fill(0, 20, 1), 'counted_blocks' => range(5969496, 5969516),
    ]);
});

it('leaves an empty table and rows with either current window marker unchanged', function (): void {
    $migration = require database_path('migrations/2027_03_07_000000_convert_legacy_problem_evidence.php');
    $migration->up();
    expect(DB::table('problem_fingerprints')->count())->toBe(0);

    foreach (['{"observation_counts":[]}', '{"counted_blocks":[]}', '{"observation_counts":null}', '{"counted_blocks":null}', '{"observation_times":["2026-10-01T10:00:00Z"],"observation_counts":[4],"counted_blocks":[5969496]}'] as $index => $evidence) {
        DB::table('problem_fingerprints')->insert(['fingerprint' => 'log|current-'.$index, 'source' => 'log', 'occurrences' => 7, 'evidence' => $evidence]);
    }
    $before = DB::table('problem_fingerprints')->orderBy('id')->get()->all();

    $migration->up();
    $migration->down();

    expect(DB::table('problem_fingerprints')->orderBy('id')->get()->all())->toEqual($before);
});
