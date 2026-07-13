<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;

final readonly class OperationTokenConsumptionGuard
{
    public function __construct(
        private DatabaseLockRetry $databaseLockRetry,
    ) {}

    public function consume(string $operationId): OperationTokenConsumptionResult
    {
        return $this->databaseLockRetry->transaction(static function () use (
            $operationId,
        ): OperationTokenConsumptionResult {
            $consumedRows = DB::table('operation_runs')
                ->where('id', $operationId)
                ->whereNull('operation_token_consumed_at')
                ->update([
                    'operation_token_consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($consumedRows > 0) {
                return OperationTokenConsumptionResult::Consumed;
            }

            $runExists = DB::table('operation_runs')
                ->where('id', $operationId)
                ->exists();

            return $runExists
                ? OperationTokenConsumptionResult::AlreadyDispatched
                : OperationTokenConsumptionResult::MissingOperation;
        });
    }
}
