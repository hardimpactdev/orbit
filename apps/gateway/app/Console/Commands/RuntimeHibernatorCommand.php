<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Processes\RuntimeIdleHibernation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('orbit-runtime-hibernator
    {--once : Run one hibernation sweep and exit}
    {--max-sweeps= : Stop after this many sweeps}
    {--sleep-seconds= : Override the interval between completed sweeps}')]
#[Description('Run the Orbit development runtime hibernation daemon')]
class RuntimeHibernatorCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(RuntimeIdleHibernation $hibernation): int
    {
        $maxSweeps = $this->maxSweeps();
        $completedSweeps = 0;

        do {
            $hibernation->hibernate();
            $completedSweeps++;

            $this->line(sprintf(
                'Runtime hibernation sweep completed at %s',
                CarbonImmutable::now()->toIso8601String(),
            ));

            if ($this->option('once') === true || $maxSweeps !== null && $completedSweeps >= $maxSweeps) {
                return self::SUCCESS;
            }

            Sleep::sleep($this->sleepSeconds());
        } while (true);
    }

    private function maxSweeps(): ?int
    {
        $value = $this->option('max-sweeps');

        if ($value === null) {
            return null;
        }

        return max(1, (int) $value);
    }

    private function sleepSeconds(): int
    {
        $value = $this->option('sleep-seconds');

        if ($value !== null) {
            return max(1, (int) $value);
        }

        $intervalMinutes = max(
            1,
            (int) config('orbit.runtime_hibernation.sweep_interval_minutes', default: 10),
        );

        return $intervalMinutes * 60;
    }
}
