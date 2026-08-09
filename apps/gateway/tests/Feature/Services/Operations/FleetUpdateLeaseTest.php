<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Models\UpdateLease;
use App\Services\Operations\FleetUpdateLease;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reserves the fleet before launch and allows exactly one runner to claim it', function (): void {
    $run = fleet_update_lease_run();
    $leases = app(FleetUpdateLease::class);

    $reservation = $leases->reserve($run);
    $claimed = $leases->claim($run, $reservation->id);

    expect($reservation->resource_type)
        ->toBe('fleet')
        ->and($reservation->resource_key)
        ->toBe('update-all')
        ->and($reservation->operation_run_id)
        ->toBe($run->id)
        ->and($claimed->owner_token)
        ->not
        ->toBe($reservation->owner_token)
        ->and($claimed->active_resource_key)
        ->toBe('fleet:update-all')
        ->and(fn () => $leases->claim($run, $reservation->id))
        ->toThrow(RuntimeException::class, 'owner token mismatch');
});

it('releases an unclaimed reservation with its reservation owner', function (): void {
    $run = fleet_update_lease_run();
    $leases = app(FleetUpdateLease::class);
    $reservation = $leases->reserve($run);

    $released = $leases->releaseReservation($run, $reservation);

    expect($released->active_resource_key)
        ->toBeNull()
        ->and($released->released_at)
        ->not
        ->toBeNull()
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())
        ->toBe(0);
});

function fleet_update_lease_run(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}
