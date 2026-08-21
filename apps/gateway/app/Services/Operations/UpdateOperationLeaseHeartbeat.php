<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\UpdateLease;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;
use SensitiveParameter;

final readonly class UpdateOperationLeaseHeartbeat
{
    public function __construct(
        private DatabaseLockRetry $databaseLockRetry,
    ) {}

    public function renew(
        OperationRun|string $operationRun,
        UpdateLease|int $fleetLease,
        #[SensitiveParameter]
        string $fleetOwnerToken,
        int $ttlSeconds,
    ): int {
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);
        $fleetOwnerToken = trim($fleetOwnerToken);

        if ($operationRunId === '' || $fleetOwnerToken === '') {
            throw new RuntimeException('Update lease heartbeat identifiers cannot be empty.');
        }

        if ($ttlSeconds < 1) {
            throw new RuntimeException('Update lease TTL must be positive.');
        }

        return $this->databaseLockRetry->transaction(function () use (
            $operationRunId,
            $fleetLease,
            $fleetOwnerToken,
            $ttlSeconds,
        ): int {
            $fleet = $this->leaseForUpdate($fleetLease);

            if (! $fleet->isActive()) {
                throw new RuntimeException('Fleet update lease is not active.');
            }

            if ($fleet->operation_run_id !== $operationRunId) {
                throw new RuntimeException('Fleet update lease operation run mismatch.');
            }

            if (! $fleet->isOwnedBy($fleetOwnerToken)) {
                throw new RuntimeException('Fleet update lease owner token mismatch.');
            }

            $now = Carbon::now();
            $activeLeases = UpdateLease::query()
                ->where('operation_run_id', $operationRunId)
                ->whereNotNull('active_resource_key')
                ->whereNull('released_at');
            /** @mago-expect analyzer:docblock-type-mismatch */
            /** @var Collection<int, UpdateLease> $expiredLeases */
            $expiredLeases = (clone $activeLeases)
                ->where('expires_at', '<=', $now)
                ->lockForUpdate()
                ->get();

            if ($expiredLeases->isNotEmpty()) {
                foreach ($expiredLeases as $lease) {
                    $lease->deactivate($now);
                    $lease->save();
                }

                return 0;
            }

            return $activeLeases->update([
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ]);
        });
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
