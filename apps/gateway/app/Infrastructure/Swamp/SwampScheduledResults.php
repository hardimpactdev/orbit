<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Jobs\Operations\ProjectSwampScheduledTerminal;
use App\Jobs\Operations\ProjectSwampScheduleFire;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/** Finds finished scheduled runs by one read per minute. Swamp's commit feed needs a v3 datastore, so it is not used. */
final readonly class SwampScheduledResults
{
    /** A first read, with no saved read time, goes back this far. */
    private const int FirstReadMinutes = 10;

    /** Finished runs are read back over this span, so a long run that ends later and a short Gateway outage lose nothing. */
    private const int WindowMinutes = 60;

    /** A queued projection is not queued again for this long; a projection that failed is tried again after it. */
    private const int QueuedSeconds = 900;

    /** A run whose projection failed this many times is left alone: Swamp keeps only the latest versions of a result, so an old run cannot be read back. */
    private const int MaxAttempts = 2;

    /** The projectors are told about the declared workflows this often, not at every read. */
    private const int ActivateSeconds = 600;

    public function __construct(private SwampCommandStep $step, private LoggerInterface $log) {}

    public function enabled(): bool
    {
        return app(DirectOperation::class)->scheduledResultDefinitions() !== []
            && config()->string('swamp.read_token') !== '' && config()->string('swamp.address') !== '';
    }

    public function poll(): void
    {
        if (! $this->enabled()) {
            return;
        }
        $declarations = app(DirectOperation::class)->scheduledResultDefinitions();
        if (Cache::add($this->key('activated'), true, self::ActivateSeconds)) {
            foreach (array_unique(array_column($declarations, 'projection')) as $projection) {
                app($projection)->activate();
            }
        }
        $started = Carbon::now();
        $saved = Cache::get($this->key('cursor'));
        // With no saved read, only recent runs are listed: their results are still in Swamp.
        $since = is_string($saved) ? Carbon::parse($saved)->subMinutes(self::WindowMinutes) : $started->copy()->subMinutes(self::FirstReadMinutes);
        $runs = $this->step->finishedRuns($since->utc()->format('Y-m-d\TH:i:s.v\Z'));
        $completed = static fn (array $run): string => is_string($run['completedAt'] ?? null) ? $run['completedAt'] : '';
        usort($runs, static fn (array $left, array $right): int => strcmp($completed($left), $completed($right)));
        $fires = $this->fireWorkflows($runs, $declarations);
        foreach ($runs as $run) {
            $id = $run['runId'] ?? null;
            $workflow = $run['workflowId'] ?? null;
            $name = $run['workflowName'] ?? null;
            if (! is_string($id) || ! Str::isUuid($id) || ! is_string($workflow) || ! is_string($name)) {
                continue;
            }
            if (isset($declarations[$workflow])) {
                if ($declarations[$workflow]['workflow'] !== $name) {
                    $this->log->error('A finished run does not match the declared scheduled workflow.', ['workflow_id' => $workflow, 'run_id' => $id]);

                    continue;
                }
                $job = new ProjectSwampScheduledTerminal($workflow, $id, is_string($run['status'] ?? null) ? $run['status'] : null);
            } elseif (isset($fires[$workflow])) {
                $job = new ProjectSwampScheduleFire($workflow, $id);
            } else {
                continue;
            }
            $this->project($job, $id);
        }
        Cache::forever($this->key('cursor'), $started->toIso8601String());
    }

    /** Remember a run whose projector accepted its exact result; a repeated read then queues nothing. */
    public static function done(string $run): void
    {
        Cache::put(self::scope().':done:'.$run, true, now()->addMinutes(self::WindowMinutes * 2));
    }

    /** Count a failed projection of a run. */
    public static function failed(string $run): void
    {
        $key = self::scope().':attempts:'.$run;
        Cache::add($key, 0, now()->addMinutes(self::WindowMinutes * 2));
        Cache::increment($key);
    }

    private function project(ProjectSwampScheduledTerminal|ProjectSwampScheduleFire $job, string $run): void
    {
        $attempts = Cache::get($this->key('attempts:'.$run));
        if (Cache::has($this->key('done:'.$run)) || (is_int($attempts) && $attempts >= self::MaxAttempts) || ! Cache::add($this->key('queued:'.$run), true, self::QueuedSeconds)) {
            return;
        }
        try {
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            Cache::forget($this->key('queued:'.$run));
            $this->log->warning('The scheduled native result is unavailable.', ['run_id' => $run, 'exception_type' => $exception::class]);
        }
    }

    /** A Schedule's own workflow fires root runs; only workflows of Schedules this Gateway holds are followed.
     * @param  list<array<string,mixed>>  $runs
     * @param  array<string,mixed>  $declarations
     * @return array<string,true>
     */
    private function fireWorkflows(array $runs, array $declarations): array
    {
        $candidates = [];
        foreach ($runs as $run) {
            $workflow = $run['workflowId'] ?? null;
            if (is_string($workflow) && Str::isUuid($workflow) && ! isset($declarations[$workflow])
                && is_string($run['workflowName'] ?? null) && preg_match('/\Aschedule-[0-9a-f-]{36}\z/D', $run['workflowName']) === 1) {
                $candidates[$workflow] = $workflow;
            }
        }
        if ($candidates === []) {
            return [];
        }

        return array_fill_keys(array_filter(Schedule::query()->whereIn('swamp_workflow_id', array_values($candidates))->pluck('swamp_workflow_id')->all(), is_string(...)), true);
    }

    private function key(string $name): string
    {
        return self::scope().':'.$name;
    }

    private static function scope(): string
    {
        return 'swamp:scheduled-results:'.hash('sha256', config()->string('swamp.address'));
    }
}
