<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Migrate alone keeps looking for the native finish after expiry, with bounded and rotating recovery reads. */
final readonly class InstanceMigrateReconciliation
{
    /** @param Closure(Operation): Operation $reconcile
     * @param  Closure(Operation): void  $forward
     */
    public function run(Closure $reconcile, Closure $forward): void
    {
        $deadline = now()->addSeconds(180);
        $seen = [];
        $current = $this->open()->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting]);
        $this->batch($current, now()->addSeconds(90), $reconcile, $forward, $seen);
        $pending = $this->open()->where('status', OperationStatus::Failed)
            ->whereNotNull('state->native_cancel_requested_at')->whereNull('state->native_cancel_confirmed_at')
            ->where('state->native_cancel_requested_at', '>', now()->subMinutes(NativeRunCancellation::ForwardMinutes)->toISOString());
        $this->batch($pending, $deadline, $reconcile, $forward, $seen);
        $recovery = $this->open()->where('status', OperationStatus::Failed)
            ->where(fn ($query) => $query->whereNull('state->native_reconcile->next_at')
                ->orWhere('state->native_reconcile->next_at', '<=', now()->toISOString()));
        $this->batch($recovery, now()->addSeconds(30)->min($deadline), $reconcile, $forward, $seen);
    }

    /** @return Builder<Operation> */
    private function open(): Builder
    {
        return Operation::query()->where('kind', 'instance.migrate')->where(fn ($query) => $query
            ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->orWhere(fn ($query) => $query->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')
                ->whereNull('state->native_timeout->no_run_verified_at')
                ->where(fn ($query) => $query->whereNull('state->native_timeout->native_terminal_status')
                    ->orWhere('state->native_timeout->native_terminal_status', 'unproven'))));
    }

    /** @param Builder<Operation> $query
     * @param  Closure(Operation): Operation  $reconcile
     * @param  Closure(Operation): void  $forward
     * @param  list<int>  $seen
     */
    private function batch(Builder $query, Carbon $deadline, Closure $reconcile, Closure $forward, array &$seen): void
    {
        foreach ($query->whereNotIn('id', $seen)->orderByRaw('cancel_requested_at IS NULL')->orderBy('state->native_reconcile->checked_at')->orderBy('id')->limit(100)->get() as $operation) {
            if (! $deadline->isFuture()) {
                break;
            }
            $seen[] = $operation->id;
            DB::transaction(function () use ($operation): void {
                $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
                $previous = $current->state['native_reconcile'] ?? [];
                $attempt = is_array($previous) && is_int($previous['attempt'] ?? null) ? $previous['attempt'] : 0;
                $attempt = InstanceMigrateProjection::unsettled($current) ? min(7, $attempt + 1) : 0;
                $current->update(['state' => [...($current->state ?? []), 'native_reconcile' => [
                    'checked_at' => now()->toISOString(), 'attempt' => $attempt,
                    'next_at' => $attempt === 0 ? null : now()->addSeconds(min(3600, 60 * (2 ** ($attempt - 1))))->toISOString(),
                ]]]);
            });
            try {
                $operation = InstanceMigrateReadDeadline::during($deadline, fn (): Operation => $reconcile($operation->refresh()));
            } catch (Throwable $exception) {
                $this->warn($operation, $exception);
            }
            try {
                if (app(NativeRunCancellation::class)->awaitsForwarding($operation->refresh()) && now()->lessThan($deadline)) {
                    InstanceMigrateReadDeadline::during($deadline, fn () => $forward($operation));
                }
            } catch (Throwable $exception) {
                $this->warn($operation, $exception);
            }
        }
    }

    private function warn(Operation $operation, Throwable $exception): void
    {
        Log::warning('The migrate Operation could not be reconciled with its Swamp run; the next pass tries again.',
            ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
    }
}
