<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRunProjection;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\GatewayRelease;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->releaseHome = sys_get_temp_dir().'/orbit-projection-'.bin2hex(random_bytes(6));
    mkdir($this->releaseHome, 0700);
    config(['orbit.home' => $this->releaseHome]);
});

afterEach(function (): void {
    File::deleteDirectory($this->releaseHome);
});

/** Ends one admitted `gateway.release` run with the given native terminal and returns its record. */
function endReleaseRun(string $status, array $result = [], ?string $failedStep = null, string $detail = '', array $steps = [], bool $retained = false, bool $reconcile = false, bool $container = false, ?string $trigger = null): GatewayRelease
{
    $sha = str_repeat('ab', 20);
    $record = GatewayRelease::query()->create(['requested' => $sha, 'trigger' => $trigger ?? ($reconcile ? 'reconcile' : 'auto'), 'outcome' => GatewayRelease::Running,
        'force' => false, 'phases' => [], 'duration_ms' => 0]);
    $operation = Operation::query()->create(['kind' => 'gateway.release', 'target_type' => 'gateway_release', 'target_id' => $record->id,
        'node_id' => null, 'status' => 'running', 'input' => ['sha' => $sha, 'id' => substr($sha, 0, 12), 'previous' => str_repeat('cd', 6), ...($container ? ['target' => 'gateway'] : []), 'retained' => $retained, 'reconcile' => $reconcile],
        'triggered_by' => 'gateway-release', 'state' => ['swamp_run_id' => 'b3c9b6a2-0c1d-4f7e-9a51-2a1f3c4d5e6f', 'native_progress' => ['steps' => $steps]]]);
    $record->update(['phases' => ['run' => ['operation_id' => $operation->id]]]);
    new GatewayReleaseRunProjection()->apply($operation, ['run_id' => 'b3c9b6a2-0c1d-4f7e-9a51-2a1f3c4d5e6f', 'status' => $status,
        'started_at' => '2026-11-04T16:00:00.000Z', 'finished_at' => '2026-11-04T16:01:00.000Z', 'result' => $result,
        ...($failedStep === null ? [] : ['error_code' => 'operation.native_failed', 'failure' => ['failed_step' => $failedStep, 'detail' => $detail]])]);

    return $record->refresh();
}

it('records a succeeded run as verified with the version the fleet rollout reads', function (): void {
    $record = endReleaseRun('succeeded', ['snapshot' => "/home/orbit/.orbit/backups/pre.sqlite\n"]);

    expect($record->outcome)->toBe('verified')
        ->and($record->snapshot_path)->toBe('/home/orbit/.orbit/backups/pre.sqlite')
        ->and($record->phases['verify'])->toBe(['outcome' => 'passed', 'status' => 'ok', 'version' => str_repeat('ab', 20)]);
});

it('records a retained rollback without a migration even when an uncertain handover pauses releases', function (string $status, ?string $step, string $outcome): void {
    $record = endReleaseRun($status, failedStep: $step, retained: true);

    expect($record->outcome)->toBe($outcome)
        ->and($record->migrations_ran)->toBeFalse()
        ->and($record->snapshot_path)->toBeNull();
})->with([
    ['succeeded', null, 'verified'],
    ['failed', 'Hand over the release', 'paused'],
    ['failed', 'Release unused candidate', 'paused'],
]);

it('commits the manual pin decision with the verified record', function (): void {
    Queue::fake();
    $record = endReleaseRun('succeeded', trigger: 'deploy');

    expect($record->outcome)->toBe('verified')
        ->and($record->phases['newest_green']['outcome'])->toBe('unknown');
    Queue::assertPushed(AdmitFleetRollout::class, fn (AdmitFleetRollout $job): bool => $job->releaseId === $record->id);
});

it('records a failed check that the run switched back from as switched back', function (): void {
    $record = endReleaseRun('failed', [], 'Hand over the release', "gateway-rollback: reloaded php8.5-fpm\ngateway.release_switched_back: the check failed; release cdcdcdcdcdcd is current again\n");

    expect($record->outcome)->toBe('switched_back')
        ->and($record->error_code)->toBe('gateway.release_verify_failed')
        ->and($record->migrations_ran)->toBeTrue()
        ->and($record->phases['switch_back'])->toBe(['outcome' => 'switched_back', 'to' => str_repeat('cd', 6)]);
});

it('pauses a hand-over whose switch-back failed, and fails a build without changing anything', function (string $step, string $detail, string $outcome): void {
    $record = endReleaseRun('failed', [], $step, $detail);

    expect($record->outcome)->toBe($outcome)
        ->and($record->phases)->not->toHaveKey('switch_back');
})->with([
    'switch-back failed' => ['Hand over the release', "gateway-rollback: release cdcdcdcdcdcd is not sealed\n", 'paused'],
    'build' => ['Build the release', '', 'failed'],
]);

it('pauses a failed migration and records the end of its output, which the failed exec would not keep', function (): void {
    $output = "  INFO  Running migrations.\n\n  2027_01_25_000000_drop_task_vm_slots_setting .... FAIL\n\n   Illuminate\\Database\\QueryException\n\n  no such index: task_vms_name_unique (Connection: mysql://orbit:hunter2@db/orbit)\nmigrate-exit=1\n";
    $record = endReleaseRun('failed', ['snapshot' => "/home/orbit/.orbit/backups/pre.sqlite\n", 'migrate' => $output], 'Check the migration', 'The migration failed; the release record shows its output.');

    expect($record->outcome)->toBe('paused')
        ->and($record->error_code)->toBe('gateway.release_migrate_failed')
        ->and($record->migrations_ran)->toBeTrue()
        ->and($record->message)->toContain('at Check the migration.')->toContain('no such index: task_vms_name_unique')->toEndWith('migrate-exit=1')
        // The message reaches the release alert, so a credential in the output is redacted.
        ->not->toContain('hunter2');
});

it('queues the fleet rollout admission after a verified release only', function (): void {
    Queue::fake();
    config(['fleet.rollout' => true]);
    expect(endReleaseRun('failed', [], 'Migrate')->phases)->not->toHaveKey('fleet');
    Queue::assertNotPushed(AdmitFleetRollout::class);

    GatewayRelease::query()->delete();
    expect(endReleaseRun('succeeded')->phases['fleet'])->toBe(['status' => 'pending', 'nodes' => []]);
    Queue::assertPushed(AdmitFleetRollout::class, 1);
});

it('warns on a verified release when the Swamp Node\'s Ansible collections differ from the release\'s, with the converge that installs them', function (): void {
    $steps = [['name' => 'checkout', 'job' => 'load', 'status' => 'succeeded'], ['name' => 'Check the Ansible collections', 'job' => 'load', 'status' => 'failed'],
        ['name' => 'load', 'job' => 'load', 'status' => 'succeeded']];
    $record = endReleaseRun('succeeded', steps: $steps);

    expect($record->outcome)->toBe('verified')
        ->and($record->phases['swamp'])->toBe(['warning' => 'swamp.collections_drift', 'fix' => 'orbit node:role:add 209 swamp --converge']);
});

it('records no collections warning when the check passed', function (): void {
    $record = endReleaseRun('succeeded', steps: [['name' => 'Check the Ansible collections', 'job' => 'load', 'status' => 'succeeded']]);

    expect($record->phases)->not->toHaveKey('swamp');
});

it('records a busy refusal with the blocker IDs and pauses after migration without loading or switching', function (): void {
    $record = endReleaseRun('failed', [], 'Wait for active Operations', 'gateway.release_busy: Active Operations after 600 seconds: 41, 42');

    expect($record->outcome)->toBe('paused')
        ->and($record->error_code)->toBe('gateway.release_busy')
        ->and($record->migrations_ran)->toBeTrue()
        ->and($record->message)->toContain('41, 42')
        ->and($record->phases['idle'])->toBe(['outcome' => 'failed', 'error_code' => 'gateway.release_busy']);
});

it('pauses a release whose run ended without a readable result as a failed run, and one that timed out as timed out', function (string $code, string $phase): void {
    $sha = str_repeat('ab', 20);
    $record = GatewayRelease::query()->create(['requested' => $sha, 'trigger' => 'auto', 'outcome' => GatewayRelease::Running,
        'force' => false, 'phases' => [], 'duration_ms' => 0]);
    $operation = Operation::query()->create(['kind' => 'gateway.release', 'target_type' => 'gateway_release', 'target_id' => $record->id,
        'node_id' => null, 'status' => 'failed', 'error_code' => $code, 'input' => ['sha' => $sha, 'id' => substr($sha, 0, 12), 'previous' => str_repeat('cd', 6)],
        'triggered_by' => 'gateway-release', 'state' => ['swamp_run_id' => 'b3c9b6a2-0c1d-4f7e-9a51-2a1f3c4d5e6f']]);
    $record->update(['phases' => ['run' => ['operation_id' => $operation->id]]]);

    new GatewayReleaseRunProjection()->timedOut($operation);

    expect($record->refresh()->outcome)->toBe('paused')
        ->and($record->phases['run']['error_code'])->toBe($phase);
})->with([
    'unreadable result' => ['operation.result_unreadable', 'gateway.release_run_failed'],
    'timeout' => ['operation.timed_out', 'gateway.release_run_timed_out'],
]);

it('records a recovered serving release without a migration, a fleet rollout or a nested refresh', function (): void {
    Queue::fake();
    $record = endReleaseRun('succeeded', reconcile: true);
    expect($record->outcome)->toBe('reconciled')
        ->and($record->phases['verify']['version'])->toBe(str_repeat('ab', 20))
        ->and($record->migrations_ran)->toBeFalse()
        ->and($record->snapshot_path)->toBeNull()
        ->and($record->phases)->not->toHaveKey('fleet');
    Queue::assertNotPushed(AdmitFleetRollout::class);
});

it('keeps a failed recovery unverified after the serving revision changed', function (): void {
    $record = endReleaseRun('failed', failedStep: 'Verify the serving release', reconcile: true);
    expect($record->outcome)->toBe('failed')->and($record->migrations_ran)->toBeFalse()
        ->and($record->error_code)->toBe('gateway.release_reconcile_not_live');
});

it('records managed handover and migration failures without a global pause or an unverified switch-back', function (): void {
    $migration = endReleaseRun('failed', ['migrate' => "failed schema change\nmigrate-exit=1"], 'Check the migration', container: true);
    expect($migration->outcome)->toBe('failed')->and($migration->migrations_ran)->toBeTrue()
        ->and($migration->retryable)->toBeFalse()->and($migration->message)->toContain('failed schema change');
    $handover = endReleaseRun('failed', failedStep: 'Confirm the Gateway handover', detail: 'gateway.release_switched_back: stale result', container: true);
    expect($handover->outcome)->toBe('failed')->and($handover->error_code)->toBe('gateway.release_verify_failed')
        ->and($handover->phases)->not->toHaveKey('switch_back')
        ->and(GatewayRelease::failedShas())->toContain($migration->sha);
});

it('records the live SHA from the host inventory even when the failed candidate already serves', function (): void {
    $base = sys_get_temp_dir().'/orbit-serving-record-'.bin2hex(random_bytes(5));
    config(['orbit.gateway_checkout' => $base.'/orbit/apps/gateway']);
    $path = GatewayReleaseLayout::fromConfig()->containerInventoryPath();
    mkdir(dirname($path), 0700, true);
    try {
        $sha = str_repeat('ab', 20);
        file_put_contents($path, json_encode(['version' => 2, 'live' => $sha, 'old' => null]));
        $record = endReleaseRun('failed', failedStep: 'Hand over the release', container: true);
        expect($record->phases['serving']['sha'])->toBe($sha);
        file_put_contents($path, 'unreadable');
        $unknown = endReleaseRun('failed', failedStep: 'Hand over the release', container: true);
        expect($unknown->phases)->not->toHaveKey('serving');
    } finally {
        unlink($path);
        rmdir(dirname($path));
        rmdir($base);
    }
});

it('keeps migration failures excluded while a same-live reconciliation preserves the automation pause state', function (): void {
    $failed = endReleaseRun('failed', failedStep: 'Check the migration', container: true);
    $automation = app(GatewayReleaseAutomation::class);
    expect($automation->pause())->toBeNull();
    $automation->pauseFor('held', $failed);
    $reconciled = endReleaseRun('succeeded', reconcile: true, container: true);
    expect($reconciled->outcome)->toBe('reconciled')
        ->and($automation->pause()['record'])->toBe($failed->id)
        ->and(GatewayRelease::failedShas())->toContain($failed->sha)
        ->and(GatewayRelease::query()->where('outcome', 'verified')->exists())->toBeFalse();
});
