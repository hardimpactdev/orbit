<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * A kind with a Node step that earlier results may make unneeded, such as `route.destroy`'s removal of a link that a read found
 * in a file's place: when its `after` steps are done and `needed` answers false, the engine records the step done without
 * sending an order, so the steps after it go on.
 */
interface EngineSkip
{
    /** Whether the Node step `$step` must run, from the results of the steps it waits for. */
    public function needed(Operation $operation, string $step, EngineRun $run): bool;
}
