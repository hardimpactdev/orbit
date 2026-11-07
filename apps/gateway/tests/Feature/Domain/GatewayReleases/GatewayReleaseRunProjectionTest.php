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
function endReleaseRun(string $status, array $result = [], ?string $failedStep = null, string $detail = ''): GatewayRelease
{
    $sha = str_repeat('ab', 20);
    $record = GatewayRelease::query()->create(['requested' => $sha, 'trigger' => 'auto', 'outcome' => GatewayRelease::Running,
        'force' => false, 'phases' => [], 'duration_ms' => 0]);
    $operation = Operation::query()->create(['kind' => 'gateway.release', 'target_type' => 'gateway_release', 'target_id' => $record->id,
        'node_id' => null, 'status' => 'running', 'input' => ['sha' => $sha, 'id' => substr($sha, 0, 12), 'previous' => str_repeat('cd', 6)],
        'triggered_by' => 'gateway-release', 'state' => ['swamp_run_id' => 'b3c9b6a2-0c1d-4f7e-9a51-2a1f3c4d5e6f']]);
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
