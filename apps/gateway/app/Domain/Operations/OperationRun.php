<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use App\Models\OperationStep;
use Closure;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * One attempt at an operation, as its kind sees it. The kind plans its steps, then runs each through `step()`.
 * A step that already succeeded is skipped and returns the value it stored, so a resumed attempt continues where the
 * last one stopped. Output of the running step is kept as an 8 KiB tail and written at most once a second.
 */
final class OperationRun
{
    public const int OutputTailBytes = 8 * 1024;

    public const float OutputFlushSeconds = 1.0;

    private ?OperationStep $current = null;

    private string $tail = '';

    private bool $truncated = false;

    private bool $dirty = false;

    private float $flushedAt = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock */
    public function __construct(
        private readonly Operation $operation,
        private readonly OperationStore $store,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    /**
     * Records the steps on the first attempt. A later attempt must plan the same steps.
     *
     * @param  list<OperationStepPlan>  $plan
     *
     * @throws OperationPlanChanged
     */
    public function plan(array $plan): void
    {
        $names = array_map(static fn (OperationStepPlan $step): string => $step->name, $plan);

        if (count($names) !== count(array_unique($names))) {
            throw new LogicException('An operation plan names a step twice.');
        }

        $existing = $this->operation->steps()->pluck('name')->all();

        if ($existing === []) {
            $this->store->plan($this->operation, $plan);

            return;
        }

        if ($existing !== $names) {
            throw new OperationPlanChanged('The resumed operation planned other steps than its first attempt.');
        }
    }

    /**
     * Returns the value this operation stored under the key, or computes it once and stores it. A resumed attempt
     * gets the same value, such as the history row that the first attempt created.
     *
     * @param  Closure(): mixed  $compute
     */
    public function remember(string $key, Closure $compute): mixed
    {
        $state = $this->operation->state ?? [];

        if (array_key_exists($key, $state)) {
            return $state[$key];
        }

        $value = $compute();
        $state[$key] = $value;
        $this->store->change($this->operation, ['state' => $state]);

        return $value;
    }

    public function cancelRequested(): bool
    {
        return Operation::query()->whereKey($this->operation->id)->whereNotNull('cancel_requested_at')->exists();
    }

    /**
     * Runs one planned step, or returns the value it stored when it already succeeded. The work returns a JSON value
     * that the later steps of a resumed attempt need, or null.
     *
     * @param  Closure(): mixed  $work
     *
     * @throws OperationCancelled when the operation was asked to stop before this step
     */
    public function step(string $name, Closure $work): mixed
    {
        $step = $this->stepNamed($name);

        if ($step->status === OperationStepStatus::Succeeded) {
            return $this->stored($name);
        }

        if ($step->status === OperationStepStatus::Failed && $step->error_code !== null) {
            // A best-effort step that failed on an earlier attempt stays failed; the attempt goes on after it.
            return null;
        }

        if ($this->cancelRequested()) {
            $this->store->change($this->operation, ['current_step' => null], $step, [
                'status' => OperationStepStatus::Cancelled,
                'finished_at' => Carbon::now(),
            ]);

            throw new OperationCancelled;
        }

        $this->current = $step;
        $this->tail = '';
        $this->truncated = false;
        $this->dirty = false;
        $this->flushedAt = ($this->clock)();
        $this->store->change($this->operation, ['current_step' => $name], $step, [
            'status' => OperationStepStatus::Running,
            'started_at' => Carbon::now(),
            'finished_at' => null,
            'error_code' => null,
            'output' => null,
            'output_truncated' => false,
        ]);

        try {
            $value = $work();
        } catch (OperationStepFailed $exception) {
            $this->finish($step, OperationStepStatus::Failed, $exception->errorCode);

            return null;
        } catch (Throwable $exception) {
            $this->finish($step, OperationStepStatus::Failed, null);

            throw $exception;
        }

        $this->finish($step, OperationStepStatus::Succeeded, null, $value);

        return $value;
    }

    /** Appends output of the running step. Output outside a step is dropped. */
    public function output(string $chunk): void
    {
        if ($this->current === null || $chunk === '') {
            return;
        }

        $this->tail .= $chunk;

        if (strlen($this->tail) > self::OutputTailBytes) {
            $this->tail = substr($this->tail, -self::OutputTailBytes);
            $this->truncated = true;
        }

        $this->dirty = true;

        if (($this->clock)() - $this->flushedAt >= self::OutputFlushSeconds) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->current === null || ! $this->dirty) {
            return;
        }

        $this->store->change($this->operation, [], $this->current, $this->outputChanges());
        $this->dirty = false;
        $this->flushedAt = ($this->clock)();
    }

    private function finish(OperationStep $step, OperationStepStatus $status, ?string $errorCode, mixed $value = null): void
    {
        $changes = ['current_step' => null];

        if ($status === OperationStepStatus::Succeeded) {
            $state = $this->operation->state ?? [];
            $steps = is_array($state['steps'] ?? null) ? $state['steps'] : [];
            $steps[$step->name] = $value;
            $state['steps'] = $steps;
            $changes['state'] = $state;
        }

        $this->store->change($this->operation, $changes, $step, [
            'status' => $status,
            'finished_at' => Carbon::now(),
            'error_code' => $errorCode,
            ...$this->outputChanges(),
        ]);
        $this->current = null;
        $this->dirty = false;
    }

    /** @return array{output: string|null, output_truncated: bool} */
    private function outputChanges(): array
    {
        return [
            'output' => $this->tail === '' ? null : self::utf8($this->tail),
            'output_truncated' => $this->truncated,
        ];
    }

    /** Replaces bytes that are not UTF-8 with U+FFFD, so the tail is valid JSON text. */
    private static function utf8(string $bytes): string
    {
        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($bytes, 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }
    }

    private function stored(string $name): mixed
    {
        $steps = $this->operation->state['steps'] ?? null;

        return is_array($steps) ? ($steps[$name] ?? null) : null;
    }

    private function stepNamed(string $name): OperationStep
    {
        $step = OperationStep::query()->where('operation_id', $this->operation->id)->where('name', $name)->first();

        return $step instanceof OperationStep ? $step : throw new LogicException("The operation did not plan the step [{$name}].");
    }
}
