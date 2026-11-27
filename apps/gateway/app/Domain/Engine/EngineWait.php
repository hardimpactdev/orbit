<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * A kind with a Gateway step that must not run before a time the recorded steps decide, such as `route.destroy`'s DNS grace
 * (docs/reference/gateway-engine.md#waits). The engine keeps that time on the step and schedules its deadline instead of
 * holding a process, so a restart of the consumer, the Gateway or Horizon resumes what is left of the wait, and a cancel
 * during it ends the run before the step runs.
 */
interface EngineWait
{
    /** The time, in ms, before which the Gateway step `$step` must not run; null when it may run at once. */
    public function waitUntilMs(Operation $operation, string $step, EngineRun $run): ?int;
}
