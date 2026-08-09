<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\UpdateLeaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    putenv('ORBIT_UPDATE_LEASE_OWNER_TOKEN');
});

it('heartbeats the fleet and nested operation leases in one isolated pass', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = update_lease_heartbeat_command_run();
    $leases = app(UpdateLeaseManager::class);
    $fleet = $leases->acquire('fleet', 'update-all', $run, 'runner-owner', 30);
    $gateway = $leases->acquire('gateway', 'orbit-gateway', $run, 'gateway-owner', 30);

    Carbon::setTestNow('2026-06-02 10:00:20');
    putenv('ORBIT_UPDATE_LEASE_OWNER_TOKEN=runner-owner');

    $this
        ->artisan('orbit:update-lease-heartbeat', [
            '--operation-run-id' => $run->id,
            '--fleet-lease-id' => $fleet->id,
            '--parent-pid' => getmypid(),
            '--ttl-seconds' => 90,
            '--interval-seconds' => 30,
            '--once' => true,
        ])
        ->assertSuccessful();

    expect($fleet->refresh()->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:01:50+00:00')
        ->and($gateway->refresh()->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:01:50+00:00');
});

function update_lease_heartbeat_command_run(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}
