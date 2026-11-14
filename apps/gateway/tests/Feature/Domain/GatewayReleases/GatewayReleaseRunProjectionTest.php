<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseRunProjection;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Jobs\TaskVms\RefreshTaskVmImages;
use App\Models\GatewayRelease;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** Ends one admitted `gateway.release` run with the given native terminal and returns its record. */
function endReleaseRun(string $status, array $result = [], ?string $failedStep = null, string $detail = '', array $steps = []): GatewayRelease
{
    $sha = str_repeat('ab', 20);
    $record = GatewayRelease::query()->create(['requested' => $sha, 'trigger' => 'auto', 'outcome' => GatewayRelease::Running,
        'force' => false, 'phases' => [], 'duration_ms' => 0]);
    $operation = Operation::query()->create(['kind' => 'gateway.release', 'target_type' => 'gateway_release', 'target_id' => $record->id,
        'node_id' => null, 'status' => 'running', 'input' => ['sha' => $sha, 'id' => substr($sha, 0, 12), 'previous' => str_repeat('cd', 6)],
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
    endReleaseRun('failed', [], 'Migrate');
    Queue::assertNotPushed(AdmitFleetRollout::class);

    GatewayRelease::query()->delete();
    endReleaseRun('succeeded');
    Queue::assertPushed(AdmitFleetRollout::class, 1);
});

it('queues no task image refresh itself: the run\'s own step admits it', function (): void {
    Queue::fake();
    endReleaseRun('succeeded');
    Queue::assertNotPushed(RefreshTaskVmImages::class);
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
