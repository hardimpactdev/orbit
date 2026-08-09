<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\UpdateLeaseManager;
use App\Services\Operations\UpdateOperationLeaseHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('heartbeats every active lease for an operation behind the fleet owner fence', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = update_operation_lease_heartbeat_run();
    $otherRun = update_operation_lease_heartbeat_run();
    $fleetOwner = hash(algo: 'sha256', data: 'fleet-runner');
    $staleFleetOwner = hash(algo: 'sha256', data: 'stale-fleet-runner');
    $leases = app(UpdateLeaseManager::class);
    $fleet = $leases->acquire('fleet', 'update-all', $run, $fleetOwner, 30);
    $gateway = $leases->acquire(
        'gateway',
        'orbit-gateway',
        $run,
        hash(algo: 'sha256', data: 'gateway'),
        30,
    );
    $node = $leases->acquire('node', 'worker-01', $run, hash(algo: 'sha256', data: 'node'), 30);
    $other = $leases->acquire(
        'scheduler',
        'orbit-scheduler',
        $otherRun,
        hash(algo: 'sha256', data: 'other'),
        30,
    );

    Carbon::setTestNow('2026-06-02 10:00:20');

    $heartbeat = app(UpdateOperationLeaseHeartbeat::class);
    $count = $heartbeat->renew(
        operationRun: $run,
        fleetLease: $fleet,
        fleetOwnerToken: $fleetOwner,
        ttlSeconds: 60,
    );

    expect($count)
        ->toBe(3)
        ->and($fleet->refresh()->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:01:20+00:00')
        ->and($gateway->refresh()->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:01:20+00:00')
        ->and($node->refresh()->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:01:20+00:00')
        ->and($other->refresh()->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:00:30+00:00')
        ->and(fn () => $heartbeat->renew(
            operationRun: $run,
            fleetLease: $fleet,
            fleetOwnerToken: $staleFleetOwner,
            ttlSeconds: 60,
        ))
        ->toThrow(RuntimeException::class, 'owner token mismatch');
});

it('deactivates expired operation leases and returns a failed renewal count', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = update_operation_lease_heartbeat_run();
    $fleetOwner = hash(algo: 'sha256', data: 'expiring-fleet-runner');
    $leases = app(UpdateLeaseManager::class);
    $fleet = $leases->acquire('fleet', 'update-all', $run, $fleetOwner, 30);
    $gateway = $leases->acquire('gateway', 'orbit-gateway', $run, hash(algo: 'sha256', data: 'gateway'), 10);

    Carbon::setTestNow('2026-06-02 10:00:20');

    $count = app(UpdateOperationLeaseHeartbeat::class)->renew(
        operationRun: $run,
        fleetLease: $fleet,
        fleetOwnerToken: $fleetOwner,
        ttlSeconds: 60,
    );

    expect($count)
        ->toBe(0)
        ->and($gateway->refresh()->active_resource_key)
        ->toBeNull()
        ->and($fleet->refresh()->active_resource_key)
        ->toBe('fleet:update-all');
});

function update_operation_lease_heartbeat_run(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}
