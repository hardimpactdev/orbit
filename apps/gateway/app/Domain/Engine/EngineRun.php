<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\OperationEngineStep;
use Illuminate\Support\Carbon;

/**
 * The steps of one Operation as the state machine last recorded them: what a definition reads to build input and its end.
 */
final readonly class EngineRun
{
    /** @param array<string, OperationEngineStep> $steps */
    public function __construct(public int $operation, public array $steps, public int $startedMs, public ?int $finishedMs) {}

    public function runId(): string
    {
        return self::runIdOf($this->operation);
    }

    public static function runIdOf(int $operation): string
    {
        return 'engine-'.$operation;
    }

    public function output(string $step): ?string
    {
        return ($this->steps[$step] ?? null)?->output;
    }

    /** The first failed step, in declaration order; a failure step's own failure never names the run's. */
    public function failed(): ?OperationEngineStep
    {
        foreach ($this->steps as $step) {
            if ($step->status === 'failed' && ! $step->on_failure && ! $step->optional) {
                return $step;
            }
        }

        return null;
    }

    public function succeeded(): bool
    {
        return array_all($this->steps, static fn (OperationEngineStep $step): bool => $step->on_failure || $step->optional || $step->status === 'done');
    }

    /** The changes all desired-state steps made, from their receipts. */
    public function changes(): int
    {
        return array_sum(array_map(static fn (OperationEngineStep $step): int => (int) ($step->output ?? 0), array_filter(
            $this->steps, static fn (OperationEngineStep $step): bool => $step->node_id > 0 && is_numeric($step->output))));
    }

    /** @return array{run_id: string, started_at: string, finished_at: string} */
    public function times(): array
    {
        $at = static fn (int $ms): string => Carbon::createFromTimestampMs($ms)->toIso8601ZuluString('millisecond');

        return ['run_id' => $this->runId(), 'started_at' => $at($this->startedMs), 'finished_at' => $at($this->finishedMs ?? $this->startedMs)];
    }
}
