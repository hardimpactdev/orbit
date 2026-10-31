<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemSource;
use App\Models\Activity;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;

it('reads only redacted Activity evidence without changing the source cursor', function (): void {
    ProblemCollectorState::query()->create(['activity_cursor' => 0]);
    $row = Activity::query()->create(['log_name' => 'commands', 'description' => 'fixture', 'command' => 'instance:create', 'status' => 'failed', 'request_id' => 'req-1', 'error_code' => 'gateway.unhandled', 'properties' => ['path' => '/instances', 'error_message' => 'password=private-sentinel', 'other' => 'private-property-sentinel']]);
    $input = app(ProblemCollector::class)->input();
    expect($input['activities'][0]['id'])->toBe($row->id)->and(json_encode($input))->not->toContain('private-sentinel', 'private-property-sentinel')
        ->and(ProblemCollectorState::query()->sole()->activity_cursor)->toBe(0)->and(ProblemFingerprint::query()->count())->toBe(0);
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
