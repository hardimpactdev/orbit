<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Exceptions\UpdateLeaseReservationExpired;
use App\Models\OperationRun;
use App\Models\UpdateLease;
use Illuminate\Support\Carbon;
use RuntimeException;
use SensitiveParameter;

final readonly class UpdateLeaseReservationClaimer
{
    public function __construct(
        private DatabaseLockRetry $databaseLockRetry,
    ) {}

    public function claim(
        UpdateLease|int $lease,
        OperationRun|string $operationRun,
        #[SensitiveParameter]
        string $reservationOwnerToken,
        #[SensitiveParameter]
        string $runnerOwnerToken,
        int $ttlSeconds,
    ): UpdateLease {
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);
        $reservationOwnerToken = trim($reservationOwnerToken);
        $runnerOwnerToken = trim($runnerOwnerToken);

        if ($operationRunId === '' || $reservationOwnerToken === '' || $runnerOwnerToken === '') {
            throw new RuntimeException('Update lease claim identifiers cannot be empty.');
        }

        if ($ttlSeconds < 1) {
            throw new RuntimeException('Update lease TTL must be positive.');
        }

        $claimed = $this->databaseLockRetry->transaction(function () use (
            $lease,
            $operationRunId,
            $reservationOwnerToken,
            $runnerOwnerToken,
            $ttlSeconds,
        ): ?UpdateLease {
            $active = $this->leaseForUpdate($lease);

            if (! $active->isActive()) {
                throw new RuntimeException('Update lease is not active.');
            }

            if ($active->operation_run_id !== $operationRunId) {
                throw new RuntimeException('Update lease operation run mismatch.');
            }

            if (! $active->isOwnedBy($reservationOwnerToken)) {
                throw new RuntimeException('Update lease owner token mismatch.');
            }

            $now = Carbon::now();

            if ($active->expires_at->lte($now)) {
                $active->deactivate($now);
                $active->save();

                return null;
            }

            $active->forceFill([
                'owner_token' => $runnerOwnerToken,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ])->save();

            return $active->refresh();
        });

        if (! $claimed instanceof UpdateLease) {
            throw new UpdateLeaseReservationExpired;
        }

        return $claimed;
    }

    private function leaseForUpdate(UpdateLease|int $lease): UpdateLease
    {
        $id = $lease instanceof UpdateLease ? $lease->id : $lease;
        /** @var int|null $lockedId */
        $lockedId = UpdateLease::query()->whereKey($id)->lockForUpdate()->value('id');

        if (! is_int($lockedId)) {
            throw new RuntimeException("Update lease [{$id}] not found.");
        }

        return UpdateLease::query()->findOrFail($lockedId);
    }
}
