<?php

declare(strict_types=1);

use App\Exceptions\UpdateLeaseReservationExpired;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\UpdateLeaseManager;
use App\Services\Operations\UpdateLeaseReservationClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('transfers active lease ownership exactly once for the expected operation', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
    $reservationOwner = hash(algo: 'sha256', data: 'reservation');
    $runnerOwner = hash(algo: 'sha256', data: 'runner');
    $secondRunnerOwner = hash(algo: 'sha256', data: 'second-runner');
    $lease = app(UpdateLeaseManager::class)->acquire('fleet', 'update-all', $run, $reservationOwner, 120);

    Carbon::setTestNow('2026-06-02 10:00:30');

    $claimer = app(UpdateLeaseReservationClaimer::class);
    $claimed = $claimer->claim(
        lease: $lease,
        operationRun: $run,
        reservationOwnerToken: $reservationOwner,
        runnerOwnerToken: $runnerOwner,
        ttlSeconds: 300,
    );

    expect($claimed->owner_token)
        ->toBe($runnerOwner)
        ->and($claimed->expires_at?->toIso8601String())
        ->toBe('2026-06-02T10:05:30+00:00')
        ->and(fn () => $claimer->claim(
            lease: $lease,
            operationRun: $run,
            reservationOwnerToken: $reservationOwner,
            runnerOwnerToken: $secondRunnerOwner,
            ttlSeconds: 300,
        ))
        ->toThrow(RuntimeException::class, 'owner token mismatch');
});

it('commits reservation expiry before reporting the failed claim', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
    $reservationOwner = hash(algo: 'sha256', data: 'expired-reservation');
    $lease = app(UpdateLeaseManager::class)->acquire('fleet', 'update-all', $run, $reservationOwner, 30);

    Carbon::setTestNow('2026-06-02 10:00:31');

    expect(fn () => app(UpdateLeaseReservationClaimer::class)->claim(
        lease: $lease,
        operationRun: $run,
        reservationOwnerToken: $reservationOwner,
        runnerOwnerToken: hash(algo: 'sha256', data: 'runner'),
        ttlSeconds: 300,
    ))
        ->toThrow(UpdateLeaseReservationExpired::class);

    expect($lease->refresh()->active_resource_key)
        ->toBeNull()
        ->and($lease->released_at?->toIso8601String())
        ->toBe('2026-06-02T10:00:31+00:00');
});
