<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Exceptions\UpdateLeaseConflict;
use App\Models\OperationRun;
use App\Models\UpdateLease;
use Illuminate\Support\Carbon;
use RuntimeException;

class UpdateLeaseManager
{
    private const array RESOURCE_TYPES = ['fleet', 'gateway', 'scheduler', 'node'];

    public function __construct(
        private readonly DatabaseLockRetry $databaseLockRetry,
    ) {}

    public function acquire(
        string $resourceType,
        string $resourceKey,
        OperationRun|string $operationRun,
        string $ownerToken,
        int $ttlSeconds,
    ): UpdateLease {
        $resourceType = trim($resourceType);
        $resourceKey = trim($resourceKey);
        $ownerToken = trim($ownerToken);
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);

        $this->assertResourceType($resourceType);
        $this->assertNonEmpty('resource key', $resourceKey);
        $this->assertNonEmpty('owner token', $ownerToken);
        $this->assertNonEmpty('operation run id', $operationRunId);

        if ($ttlSeconds < 1) {
            throw new RuntimeException('Update lease TTL must be positive.');
        }

        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($ttlSeconds);
        $activeResourceKey = $this->activeResourceKey($resourceType, $resourceKey);

        return $this->databaseLockRetry->transactionRetryingUniqueConstraints(function () use (
            $resourceType,
            $resourceKey,
            $operationRunId,
            $ownerToken,
            $now,
            $expiresAt,
            $activeResourceKey,
        ): UpdateLease {
            $active = $this->activeLease($activeResourceKey);

            if ($active instanceof UpdateLease && $active->isActive() && $active->expires_at->gt($now)) {
                throw UpdateLeaseConflict::fromLease($active);
            }

            if ($active instanceof UpdateLease) {
                $active->deactivate($now);
                $active->save();
            }

            return $this->createActiveLease(
                resourceType: $resourceType,
                resourceKey: $resourceKey,
                activeResourceKey: $activeResourceKey,
                operationRunId: $operationRunId,
                ownerToken: $ownerToken,
                expiresAt: $expiresAt,
            );
        });
    }

    public function release(UpdateLease|int $lease, string $ownerToken): UpdateLease
    {
        $ownerToken = trim($ownerToken);
        $this->assertNonEmpty('owner token', $ownerToken);

        return $this->databaseLockRetry->transaction(function () use ($lease, $ownerToken): UpdateLease {
            $active = $this->leaseForUpdate($lease);

            if (! $active->isActive()) {
                return $active;
            }

            if (! $active->isOwnedBy($ownerToken)) {
                throw new RuntimeException('Update lease owner token mismatch.');
            }

            $active->deactivate(Carbon::now());
            $active->save();

            return $active->refresh();
        });
    }

    public function heartbeat(UpdateLease|int $lease, string $ownerToken, int $ttlSeconds): UpdateLease
    {
        $ownerToken = trim($ownerToken);
        $this->assertNonEmpty('owner token', $ownerToken);

        if ($ttlSeconds < 1) {
            throw new RuntimeException('Update lease TTL must be positive.');
        }

        $expired = false;

        $heartbeat = $this->databaseLockRetry->transaction(function () use (
            $lease,
            $ownerToken,
            $ttlSeconds,
            &$expired,
        ): UpdateLease {
            $active = $this->leaseForUpdate($lease);

            if (! $active->isActive()) {
                throw new RuntimeException('Update lease is not active.');
            }

            if (! $active->isOwnedBy($ownerToken)) {
                throw new RuntimeException('Update lease owner token mismatch.');
            }

            $now = Carbon::now();

            if ($active->expires_at->lte($now)) {
                $active->deactivate($now);
                $active->save();
                $expired = true;

                return $active->refresh();
            }

            $active->forceFill([
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ])->save();

            return $active->refresh();
        });

        if ($expired) {
            throw new RuntimeException('Update lease expired before heartbeat.');
        }

        return $heartbeat;
    }

    public function withLease(
        string $resourceType,
        string $resourceKey,
        OperationRun|string $operationRun,
        string $ownerToken,
        int $ttlSeconds,
        callable $callback,
    ): mixed {
        $lease = $this->acquire(
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            operationRun: $operationRun,
            ownerToken: $ownerToken,
            ttlSeconds: $ttlSeconds,
        );

        try {
            return $callback($lease);
        } finally {
            $this->release($lease->id, $ownerToken);
        }
    }

    private function createActiveLease(
        string $resourceType,
        string $resourceKey,
        string $activeResourceKey,
        string $operationRunId,
        string $ownerToken,
        Carbon $expiresAt,
    ): UpdateLease {
        /** @var UpdateLease $lease */
        $lease = UpdateLease::query()->create([
            'resource_type' => $resourceType,
            'resource_key' => $resourceKey,
            'active_resource_key' => $activeResourceKey,
            'operation_run_id' => $operationRunId,
            'owner_token' => $ownerToken,
            'expires_at' => $expiresAt,
        ]);

        return $lease;
    }

    private function activeLease(string $activeResourceKey): ?UpdateLease
    {
        /** @var UpdateLease|null $lease */
        $lease = UpdateLease::query()
            ->where('active_resource_key', $activeResourceKey)
            ->lockForUpdate()
            ->first();

        return $lease;
    }

    private function leaseForUpdate(UpdateLease|int $lease): UpdateLease
    {
        $id = $lease instanceof UpdateLease ? $lease->id : $lease;

        /** @var UpdateLease|null $active */
        $active = UpdateLease::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $active instanceof UpdateLease) {
            throw new RuntimeException("Update lease [{$id}] not found.");
        }

        return $active;
    }

    private function activeResourceKey(string $resourceType, string $resourceKey): string
    {
        return "{$resourceType}:{$resourceKey}";
    }

    private function assertResourceType(string $resourceType): void
    {
        if (! in_array($resourceType, self::RESOURCE_TYPES, true)) {
            throw new RuntimeException('Update lease resource type must be one of fleet, gateway, scheduler, node.');
        }
    }

    private function assertNonEmpty(string $label, string $value): void
    {
        if ($value === '') {
            throw new RuntimeException("Update lease {$label} cannot be empty.");
        }
    }
}
