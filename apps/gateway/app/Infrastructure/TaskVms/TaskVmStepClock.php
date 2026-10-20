<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Models\TaskVm;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Log;

/**
 * Logs how long each step takes that brings a task VM to `ready`, so a slow step shows in the Gateway log.
 * A task VM job starts the clock; the shared code it calls, such as Node provisioning, records its steps
 * with {@see step()}, which does nothing while no clock runs. The binding is scoped, so each job starts
 * with a stopped clock. It reads the app clock, so a test that freezes time gets exact seconds.
 */
#[Scoped]
final class TaskVmStepClock
{
    private ?string $vm = null;

    private float $last = 0.0;

    /**
     * Starts the clock for `$vm`. `$since` is the Unix time the first step began, or now when null. A job
     * starts it again from an earlier time to log a total.
     */
    public function start(TaskVm $vm, ?float $since = null): void
    {
        $this->vm = $vm->name;
        $this->last = $since ?? self::now();
    }

    /** Logs the seconds since the clock started or since the previous step. */
    public function step(string $step): void
    {
        if ($this->vm === null) {
            return;
        }

        $now = self::now();
        Log::info('Task VM step done.', ['task_vm' => $this->vm, 'step' => $step, 'seconds' => round($now - $this->last, 1)]);
        $this->last = $now;
    }

    public function stop(): void
    {
        $this->vm = null;
    }

    private static function now(): float
    {
        return now()->getPreciseTimestamp() / 1_000_000;
    }
}
