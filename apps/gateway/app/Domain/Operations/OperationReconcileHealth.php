<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Tasks\TaskCheckProjection;
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

    /**
     * Called only with the completion time of an identity-checked terminal root read by this pass.
     * Nothing here may stop the pass: an Operation whose state cannot be read counts as unsettled.
     *
     * @param  bool  $projected  False when this pass failed to project the Operation.
     */
    public function observe(Operation $operation, CarbonImmutable $completedAt, bool $projected): void
    {
        try {
            $operation = $operation->refresh();
            if ($operation->status->terminal() && ! ExpireOperationAction::awaitsSettlement($operation) && ! InstanceMigrateProjection::unsettled($operation)) {
                return;
            }
            // A check whose root succeeded stays running by design while hosted CI is pending. A failed projection is no such wait.
            if ($projected && TaskCheckProjection::awaitsHostedCi($operation)) {
                return;
            }
        } catch (Throwable) {
            // Counted below: the run ended and its settlement is unproven.
        }
        $this->count++;
        if ($this->oldest === null || $completedAt->lessThan($this->oldest)) {
            $this->oldest = $completedAt;
        }
    }

    /** One small write at the job's end, including an empty pass. Recording must never fail reconciliation. */
    public function record(int $started, int $operations): void
    {
        $finished = now()->toISOString();
        $duration = round((hrtime(true) - $started) / 1_000_000, 3);
        try {
            DB::transaction(function () use ($finished, $duration): void {
                $state = ProblemCollectorState::query()->lockForUpdate()->orderBy('id')->first() ?? new ProblemCollectorState;
                $state->observation = [...($state->observation ?? []),
                    'operation_reconcile_finished_at' => $finished, 'operation_reconcile_duration_ms' => $duration,
                    'operation_settlement_oldest_completed_at' => $this->oldest?->toISOString(), 'operation_settlement_count' => $this->count];
                $state->save();
            });
            if ($this->count > 0) {
                Log::warning('Terminal Swamp runs still need Operation settlement.', [
                    'count' => $this->count, 'oldest_completed_at' => $this->oldest?->toISOString(),
                ]);
            }
        } catch (Throwable $exception) {
            rescue(fn () => Log::warning('The Operation reconcile pass facts could not be recorded.', ['exception_type' => $exception::class]), report: false);
        }
        rescue(fn () => Log::info('Operation reconcile pass ran.', [
            'duration_ms' => $duration, 'operations' => $operations, 'unsettled' => $this->count,
        ]), report: false);
    }

    /**
     * Status's existing state read also checks for candidate children. A scalar select returns a row even before the first pass.
     * The hint is request-local, never stored: an empty proof inventory costs no extra query and cannot hide new crash residue.
     *
     * @return array<string,mixed>|null
     */
    public function observation(): ?array
    {
        $row = DB::query()->selectSub(ProblemCollectorState::query()->select('observation')->orderBy('id')->limit(1), 'observation')
            ->selectSub(ProofRunSettlement::unresolvedPlaceChildren()->selectRaw('1')->limit(1), 'proof_place_child_present')->sole();
        $state = new ProblemCollectorState;
        $state->setRawAttributes((array) $row, true);
        $observation = $state->observation;

        return $state->getAttribute('proof_place_child_present') === null ? $observation : [...($observation ?? []), 'proof_place_child_present' => true];
    }

    /**
     * @param  array<string,mixed>|null  $observation
     * @return array{finished_at:?string,last_pass_ms:int|float|null,unsettled_count:int}
     */
    public function facts(?array $observation): array
    {
        $duration = $observation['operation_reconcile_duration_ms'] ?? null;
        $count = $observation['operation_settlement_count'] ?? null;

        return [
            'finished_at' => $this->timestamp($observation['operation_reconcile_finished_at'] ?? null),
            'last_pass_ms' => (is_int($duration) || is_float($duration)) && is_finite($duration) && $duration >= 0 ? $duration : null,
            'unsettled_count' => is_int($count) ? $count : 0,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $parsed = date_parse($value);
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0 || isset($parsed['relative'])) {
            return null;
        }
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $part) {
            if ($parsed[$part] === false) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>|null  $observation
     * @return list<array{code:string,count:int,since:?string,newest_since:?string,resource:null}>
     */
    public function issues(?array $observation): array
    {
        $facts = $this->facts($observation);
        $finished = $facts['finished_at'];
        $oldest = $this->timestamp($observation['operation_settlement_oldest_completed_at'] ?? null);
        $count = $facts['unsettled_count'];
        $issues = [];
        if ($finished !== null ? CarbonImmutable::parse($finished)->lessThan(now()->subMinutes(self::PassMinutes)) : $this->hasOldOpenNativeOperation()) {
            $issues[] = ['code' => 'gateway.operation_reconcile_stalled', 'count' => 1, 'since' => $finished, 'newest_since' => $finished, 'resource' => null];
        }
        if ($oldest !== null && $count > 0 && CarbonImmutable::parse($oldest)->lessThan(now()->subMinutes(self::SettlementMinutes))) {
            $issues[] = ['code' => 'gateway.operation_settlement_stuck', 'count' => $count, 'since' => $oldest, 'newest_since' => $oldest, 'resource' => null];
        }

        if (($observation['proof_place_child_present'] ?? false) === true) {
            $stale = ProofRunSettlement::stalePlaceChildren();
            if ($stale->isNotEmpty()) {
                $since = $stale->min(static fn (Operation $child): ?string => $child->updated_at?->toISOString());
                $issues[] = ['code' => 'gateway.proof_place_stale_child', 'count' => $stale->count(),
                    'since' => $since, 'newest_since' => $since, 'resource' => null];
            }
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
