<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Closure;

/** Test-only external work. No production kind waits yet. */
final class WaitingOperationKind implements OperationWaitingKind, OperationWaitingStep
{
    public int $starts = 0;

    public int $reads = 0;

    public int $stops = 0;

    public int $after = 0;

    public bool $done = false;

    public bool $stopConfirmed = true;

    public bool $requestSeenAtStop = false;

    public ?Closure $onRead = null;

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('external', false), new OperationStepPlan('after', true)]);
        $result = $run->step('external', function () use ($run): never {
            $this->starts++;
            $run->output("started\n");
            $run->wait(10);
        });
        $run->step('after', function (): void {
            $this->after++;
        });

        return OperationOutcome::succeeded(['external' => $result]);
    }

    public function waitingStep(Operation $operation, string $name): OperationWaitingStep
    {
        return $this;
    }

    public function read(OperationRun $run): mixed
    {
        $this->reads++;
        $run->output("read\n");
        ($this->onRead ?? static fn (): null => null)();

        if (! $this->done) {
            $run->wait(10);
        }

        return 'finished';
    }

    public function stop(OperationRun $run): void
    {
        $this->stops++;
        $this->requestSeenAtStop = $run->operation()->fresh()->cancel_requested_at !== null;

        if (! $this->stopConfirmed) {
            throw new ResourceOperationException('test.stop_unconfirmed', 'The external work did not confirm its stop.', 502);
        }
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        return $failure;
    }
}
