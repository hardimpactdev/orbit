<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Projects native events immediately. No history read or remote action is part of progress. */
final readonly class NativeRunProgress
{
    /** @param array<string,mixed> $event */
    public function apply(int $operationId, string $runId, array $event): void
    {
        if (! in_array($event['kind'] ?? null, ['step_started', 'step_completed', 'step_failed', 'step_skipped'], true)
            || ! is_string($event['stepId'] ?? null) || ! is_string($event['jobId'] ?? null) || ! is_int($event['seq'] ?? null)) {
            return;
        }
        DB::transaction(function () use ($operationId, $runId, $event): void {
            $operation = Operation::query()->lockForUpdate()->find($operationId);
            if ($operation === null || $operation->status->terminal() || ($operation->state['swamp_run_id'] ?? null) !== $runId) {
                return;
            }
            $state = $operation->state ?? [];
            $stored = $state['native_progress'] ?? null;
            $progress = is_array($stored) && ($stored['run'] ?? null) === $runId ? $stored : [];
            if ($event['seq'] <= (is_int($progress['seq'] ?? null) ? $progress['seq'] : 0)) {
                return;
            }
            $active = $this->active($progress['active'] ?? null);
            $parent = is_string($event['parentRunId'] ?? null) ? $event['parentRunId'] : $runId;
            $key = hash('sha256', $parent.'|'.$event['jobId'].'|'.$event['stepId']);
            if ($event['kind'] === 'step_started') {
                $started = $event['startedAt'] ?? null;
                if (! is_string($started) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $started) !== 1) {
                    $started = null;
                }
                $active[$key] = ['step' => mb_strcut($event['stepId'], 0, 255), 'started_at' => $started, 'observed_at' => now()->toISOString()];
            } else {
                unset($active[$key]);
            }
            if ($event['kind'] === 'step_failed' && ($event['allowedFailure'] ?? false) !== true && ! isset($state['native_failure'])) {
                $state['native_failure'] = app(NativeStepFailure::class)->fromRun(['status' => 'failed', 'jobs' => [['steps' => [[
                    'name' => $event['stepId'], 'status' => 'failed', 'error' => $event['error'] ?? null,
                ]]]]]);
            }
            $state['native_progress'] = ['run' => $runId, 'seq' => $event['seq'], 'active' => $active];
            $current = $active === [] ? null : end($active);
            $failure = $state['native_failure'] ?? null;
            app(OperationStore::class)->change($operation, [
                'state' => $state, 'current_step' => $current['step'] ?? null,
                'failed_step' => is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step'] : null,
            ]);
        });
    }

    /** @return array<string,array{step:string,started_at:?string,observed_at:string}> */
    private function active(mixed $value): array
    {
        $active = [];
        foreach (is_array($value) ? $value : [] as $key => $row) {
            if (is_string($key) && is_array($row) && is_string($row['step'] ?? null)
                && is_string($row['observed_at'] ?? null)) {
                $active[$key] = ['step' => $row['step'], 'started_at' => is_string($row['started_at'] ?? null) ? $row['started_at'] : null,
                    'observed_at' => $row['observed_at']];
            }
        }

        return $active;
    }
}
