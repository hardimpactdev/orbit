<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Pass facts share Doctor's state row; status never asks Swamp for evidence. */
final class OperationReconcileHealth
{
    // Five minutes allow five scheduled passes before either signal becomes an issue.
    private const int PassMinutes = 5;

    private const int SettlementMinutes = 5;

    private int $count = 0;

    private ?CarbonImmutable $oldest = null;

    /** Called only with the completion time of an identity-checked terminal root read by this pass. */
    public function observe(Operation $operation, CarbonImmutable $completedAt): void
    {
        if ($operation->status->terminal() && ! ExpireOperationAction::awaitsSettlement($operation) && ! InstanceMigrateProjection::unsettled($operation)) {
            return;
        }
        $this->count++;
        if ($this->oldest === null || $completedAt->lessThan($this->oldest)) {
            $this->oldest = $completedAt;
        }
    }

    /** One small write at the job's end, including an empty pass. Recording must never fail reconciliation. */
    public function record(int $started): void
    {
        $finished = now()->toISOString();
        $duration = round((hrtime(true) - $started) / 1_000_000, 3);
        if ($this->count > 0) {
            Log::warning('Terminal Swamp runs still need Operation settlement.', [
                'count' => $this->count, 'oldest_completed_at' => $this->oldest?->toISOString(),
            ]);
        }
        try {
            DB::transaction(function () use ($finished, $duration): void {
                $state = ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->first() ?? new ProblemCollectorState;
                $state->observation = [...($state->observation ?? []),
                    'operation_reconcile_finished_at' => $finished, 'operation_reconcile_duration_ms' => $duration,
                    'operation_settlement_oldest_completed_at' => $this->oldest?->toISOString(), 'operation_settlement_count' => $this->count];
                $state->save();
            });
        } catch (Throwable $exception) {
            Log::warning('The Operation reconcile pass facts could not be recorded.', ['exception_type' => $exception::class]);
        }
    }

    /**
     * @param  array<string,mixed>|null  $observation
     * @return list<array{code:string,count:int,since:?string,newest_since:?string,resource:null}>
     */
    public function issues(?array $observation): array
    {
        $finished = $observation['operation_reconcile_finished_at'] ?? null;
        $finished = is_string($finished) ? $finished : null;
        $oldest = $observation['operation_settlement_oldest_completed_at'] ?? null;
        $count = $observation['operation_settlement_count'] ?? 0;
        $issues = [];
        if ($finished !== null ? CarbonImmutable::parse($finished)->lessThan(now()->subMinutes(self::PassMinutes)) : $this->hasOldOpenNativeOperation()) {
            $issues[] = ['code' => 'gateway.operation_reconcile_stalled', 'count' => 1, 'since' => $finished, 'newest_since' => $finished, 'resource' => null];
        }
        if (is_string($oldest) && is_int($count) && $count > 0 && CarbonImmutable::parse($oldest)->lessThan(now()->subMinutes(self::SettlementMinutes))) {
            $issues[] = ['code' => 'gateway.operation_settlement_stuck', 'count' => $count, 'since' => $oldest, 'newest_since' => $oldest, 'resource' => null];
        }

        return $issues;
    }

    /** No heartbeat on a fresh installation is healthy until a native Operation has waited five minutes. */
    private function hasOldOpenNativeOperation(): bool
    {
        $direct = app(DirectOperation::class);
        $before = now()->subMinutes(self::PassMinutes);
        $rows = Operation::query()->whereIn('kind', array_keys($direct->definitions()))
            ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->where(fn ($query) => $query->where('started_at', '<', $before)
                ->orWhere(fn ($query) => $query->whereNull('started_at')->where('created_at', '<', $before)));
        foreach ($rows->lazyById() as $operation) {
            // Fleet's selector-less roots are also read by the reconciler's generic failure reader.
            if (! isset($direct->definition($operation->kind)['terminal_result']) || $direct->usesNativeTerminal($operation)) {
                return true;
            }
        }

        return false;
    }
}
