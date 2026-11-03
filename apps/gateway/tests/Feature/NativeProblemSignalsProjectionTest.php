<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemSource;
use App\Models\Activity;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;

it('remembers the newest Activity on the first collection without counting history', function (): void {
    ProblemCollectorState::query()->create(['activity_cursor' => null]);
    $row = Activity::query()->create(['log_name' => 'commands', 'description' => 'fixture', 'command' => 'instance:create', 'status' => 'failed', 'request_id' => 'req-0', 'error_code' => 'gateway.unhandled']);
    $signals = app(ProblemCollector::class)->signals();
    expect($signals['activity_cursor_before'])->toBeNull()->and($signals['activity_cursor_after'])->toBe($row->id)->and($signals['activities'])->toBe([]);
});
it('selects server failures with redacted evidence and moves the cursor past ignored client errors', function (): void {
    ProblemCollectorState::query()->create(['activity_cursor' => 0]);
    $failed = Activity::query()->create(['log_name' => 'commands', 'description' => 'fixture', 'command' => 'instance:create', 'status' => 'failed', 'request_id' => 'req-1', 'error_code' => 'gateway.unhandled', 'properties' => ['path' => '/instances', 'error_message' => 'password=private-sentinel', 'other' => 'private-property-sentinel']]);
    $server = Activity::query()->create(['log_name' => 'commands', 'description' => 'fixture', 'command' => 'node:show', 'status' => 'failed', 'request_id' => 'req-2', 'error_code' => 'http.503']);
    $client = Activity::query()->create(['log_name' => 'commands', 'description' => 'fixture', 'command' => 'node:show', 'status' => 'failed', 'request_id' => 'req-3', 'error_code' => 'http.404']);
    $signals = app(ProblemCollector::class)->signals();
    expect(array_column($signals['activities'], 'key'))->toBe(['instance:create|gateway.unhandled', 'node:show|http.503'])
        ->and($signals['activities'][0]['observation'])->toMatchArray(['request_ids' => ['req-1'], 'activity_ids' => [$failed->id], 'paths' => ['/instances']])
        ->and($signals['activities'][1]['observation'])->toBe(['request_ids' => ['req-2'], 'activity_ids' => [$server->id], 'paths' => []])
        ->and(json_encode($signals))->not->toContain('private-sentinel', 'private-property-sentinel')
        ->and($signals['activity_cursor_after'])->toBe($client->id)->and($server->id)->toBeLessThan($client->id)
        ->and(ProblemCollectorState::query()->sole()->activity_cursor)->toBe(0)->and(ProblemFingerprint::query()->count())->toBe(0);
});
it('keys assistance by its reason with ids and numbers replaced', function (): void {
    expect(ProblemCollector::reasonKey("  Retry  42 for\tc0ffee00-1234-4abc-9def-0123456789ab "))->toBe('retry # for #')
        ->and(ProblemCollector::reasonKey('   '))->toBe('');
});
it('commits native Activity signals and cursor once and refuses stale collection cursors', function (): void {
    $collector = app(ProblemCollector::class);
    $native = ['activity_cursor_before' => null, 'activity_cursor_after' => 19, 'activities' => [], 'open_assistance_ids' => [], 'assistance' => []];
    $collector->recordSignals($native);
    expect(ProblemCollectorState::query()->sole()->activity_cursor)->toBe(19);
    $native['activity_cursor_before'] = 19;
    $native['activity_cursor_after'] = 20;
    $native['activities'] = [['key' => 'instance:create|gateway.unhandled', 'seen_at' => '2026-10-31T10:00:00Z', 'observation' => ['request_ids' => ['req-1'], 'activity_ids' => [20]]]];
    $collector->recordSignals($native);
    $collector->recordSignals($native);
    expect(ProblemCollectorState::query()->sole()->activity_cursor)->toBe(20)->and(ProblemFingerprint::query()->sole()->occurrences)->toBe(1)
        ->and(ProblemFingerprint::query()->sole()->evidence['observation_counts'])->toBe([1]);
});
it('prunes native-selected closed assistance and applies bounded fingerprint evidence', function (): void {
    $row = ProblemFingerprint::query()->create(['fingerprint' => 'assist|retry #', 'source' => ProblemSource::Assist, 'occurrences' => 1, 'evidence' => ['assistance_task_ids' => [1, 2]]]);
    app(ProblemCollector::class)->recordSignals(['activity_cursor_after' => 0, 'activities' => [], 'open_assistance_ids' => [2], 'assistance' => [['key' => 'retry #', 'observation' => ['assistance_task_ids' => [3], 'assistance_reason' => 'Retry 3']]]]);
    expect($row->refresh()->evidence['assistance_task_ids'])->toBe([2, 3]);
});
