<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\UpdateLease;

final readonly class FleetUpdateLease
{
    public const string RESOURCE_KEY = 'update-all';

    public function __construct(
        private UpdateLeaseManager $leases,
        private UpdateLeaseReservationClaimer $reservationClaimer,
    ) {}

    public function reserve(OperationRun $operationRun): UpdateLease
    {
        return $this->leases->acquire(
            resourceType: 'fleet',
            resourceKey: self::RESOURCE_KEY,
            operationRun: $operationRun,
            ownerToken: $this->reservationOwnerToken($operationRun),
            ttlSeconds: $this->reservationTtlSeconds(),
        );
    }

    public function claim(OperationRun $operationRun, int $leaseId): UpdateLease
    {
        return $this->reservationClaimer->claim(
            lease: $leaseId,
            operationRun: $operationRun,
            reservationOwnerToken: $this->reservationOwnerToken($operationRun),
            runnerOwnerToken: $this->newRunnerOwnerToken(),
            ttlSeconds: $this->runtimeTtlSeconds(),
        );
    }

    public function acquireForRunner(OperationRun $operationRun): UpdateLease
    {
        return $this->leases->acquire(
            resourceType: 'fleet',
            resourceKey: self::RESOURCE_KEY,
            operationRun: $operationRun,
            ownerToken: $this->newRunnerOwnerToken(),
            ttlSeconds: $this->runtimeTtlSeconds(),
        );
    }

    public function releaseReservation(OperationRun $operationRun, UpdateLease|int $lease): UpdateLease
    {
        return $this->leases->release($lease, $this->reservationOwnerToken($operationRun));
    }

    public function releaseRunner(UpdateLease $lease): UpdateLease
    {
        return $this->leases->release($lease, $lease->owner_token);
    }

    public function runtimeTtlSeconds(): int
    {
        return max(1, (int) config(key: 'orbit.updates.lease_ttl_seconds', default: 300));
    }

    private function reservationTtlSeconds(): int
    {
        return max(1, (int) config(key: 'orbit.updates.reservation_ttl_seconds', default: 120));
    }

    private function reservationOwnerToken(OperationRun $operationRun): string
    {
        return hash(algo: 'sha256', data: "update-all-reservation:{$operationRun->id}");
    }

    private function newRunnerOwnerToken(): string
    {
        return hash(algo: 'sha256', data: random_bytes(length: 32));
    }
}
