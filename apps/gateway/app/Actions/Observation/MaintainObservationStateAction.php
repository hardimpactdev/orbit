<?php

declare(strict_types=1);

namespace App\Actions\Observation;

use App\Actions\Activities\FinalizeInterruptedActivitiesAction;
use App\Domain\Doctor\DoctorCollectionReads;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Lock;

/** Narrow Gateway state maintenance; no caller command or Node execution. */
final readonly class MaintainObservationStateAction
{
    /** @return array<string, int|string|bool> */
    public function execute(string $routine, string $run): array
    {
        $key = 'observation:'.$routine.':'.$run;

        $lock = Cache::lock('observation:'.$routine.':lock', 60);
        $lock->block(2);
        try {
            $saved = Cache::get($key);
            if (is_array($saved)) {
                $receipt = ['status' => Arr::string($saved, 'status')];
                foreach (['finalized', 'requeued', 'pruned'] as $field) {
                    if (array_key_exists($field, $saved)) {
                        $receipt[$field] = Arr::integer($saved, $field);
                    }
                }

                return $receipt;
            }
            $receipt = $this->apply($routine);
            Cache::put($key, $receipt, now()->addDays(7));

            return $receipt;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, int|string|bool> */
    private function apply(string $routine): array
    {
        if (in_array($routine, ['operations', 'metrics'], true) && ! GatewayQueueRedis::configured()) {
            return ['status' => 'skipped'];
        }
        if (in_array($routine, ['health', 'batches'], true) && ! app(TaskExtensionState::class)->enabled()) {
            return ['status' => 'disabled'];
        }

        return match ($routine) {
            'activities' => ['status' => 'succeeded', 'finalized' => app(FinalizeInterruptedActivitiesAction::class)->execute()],
            'operations' => ['status' => 'succeeded', 'requeued' => app(OperationSweeper::class)->sweep()],
            'metrics' => $this->metrics(),
            'health' => $this->health(),
            'batches' => $this->batches(),
            'failed' => $this->failed(),
            default => throw new ResourceOperationException('observation.routine_invalid', 'Unknown observation routine.', 422),
        };
    }

    /** @return array{status: string} */
    private function health(): array
    {
        app(CommandDeadline::class)->withinForwardWork(DoctorCollectionReads::ProbeSeconds, fn () => app(ProblemCollector::class)->collectHorizon());

        return ['status' => 'succeeded'];
    }

    /** @return array{status: string} */
    private function metrics(): array
    {
        if (app(Lock::class)->get('metrics:snapshot', config()->integer('horizon.metrics.snapshot_lock', 300) - 30)) {
            app(MetricsRepository::class)->snapshot();
        }

        return ['status' => 'succeeded'];
    }

    /** @return array{status: string, pruned: int} */
    private function batches(): array
    {
        $repository = app(BatchRepository::class);
        $cutoff = now()->subHours(config()->integer('retention.job_batch_hours'));
        $count = $repository->prune($cutoff) + $repository->pruneUnfinished($cutoff) + $repository->pruneCancelled($cutoff);

        return ['status' => 'succeeded', 'pruned' => $count];
    }

    /** @return array{status: string, pruned: int} */
    private function failed(): array
    {
        $provider = app('queue.failer');

        return ['status' => 'succeeded', 'pruned' => $provider->prune(now()->subHours(config()->integer('retention.failed_jobs_hours')))];
    }
}
