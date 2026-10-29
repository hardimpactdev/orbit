<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Gateway-owned health remains available when Swamp has stopped. */
final readonly class OperationHealth
{
    public function __construct(private OperationExecutors $executors) {}

    /** @return array{error_code:string}|null */
    public function assistance(Operation $operation): ?array
    {
        if ($operation->status->terminal()) {
            return null;
        }
        $assistance = $operation->state['executor_assistance'] ?? null;
        $flag = is_array($assistance) ? ($assistance['error_code'] ?? null) : null;
        if (in_array($flag, ['operation.child_unproven', 'operation.kind_unavailable', 'operation.reclaim_limit'], true)) {
            return ['error_code' => $flag];
        }
        if (! $this->executors->swamp($operation->kind)) {
            return null;
        }
        $unclaimed = $operation->status === OperationStatus::Queued && $operation->updated_at?->lessThanOrEqualTo(now()->subMinutes(5));
        if ($operation->status === OperationStatus::Waiting) {
            $unclaimed = $operation->children()->where('status', OperationStatus::Queued)
                ->where('updated_at', '<=', now()->subMinutes(5))->exists();
        }
        $code = match (true) {
            $unclaimed => 'operation.executor_unclaimed',
            in_array($operation->status, [OperationStatus::Running, OperationStatus::Waiting], true)
                && $operation->updated_at?->lessThanOrEqualTo(now()->subMinutes(15)) => 'operation.no_progress',
            $operation->attempts > 3 => 'operation.reclaim_churn',
            default => null,
        };

        return $code === null ? null : ['error_code' => $code];
    }

    /** @return array<int,string> */
    public function check(): array
    {
        $alerts = [];
        $reported = Activity::query()->where('command', 'operation:health')->where('subject_type', Operation::class)->select('subject_id');
        foreach (Operation::query()->where(fn ($query) => $query->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->orWhereIn('id', $reported))->lazyById(100) as $candidate) {
            if (! $this->executors->swamp($candidate->kind)) {
                continue;
            }
            $code = DB::transaction(function () use ($candidate): ?string {
                $operation = Operation::query()->lockForUpdate()->findOrFail($candidate->id);
                $code = $this->assistance($operation)['error_code'] ?? null;
                $previous = Activity::query()->where('command', 'operation:health')->where('subject_type', Operation::class)
                    ->where('subject_id', $operation->id)->latest('id')->first();
                if ($previous?->error_code !== $code) {
                    Activity::query()->create(['log_name' => 'operations', 'description' => $code ?? 'operation.health_recovered',
                        'subject_type' => Operation::class, 'subject_id' => $operation->id, 'request_id' => (string) Str::uuid(),
                        'command' => 'operation:health', 'status' => $code === null ? 'completed' : 'failed', 'error_code' => $code,
                        'properties' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'sequence' => $operation->sequence, 'attempts' => $operation->attempts]]);
                }

                return $code;
            });
            if ($code !== null) {
                $alerts[$candidate->id] = $code;
            }
        }

        return $alerts;
    }
}
