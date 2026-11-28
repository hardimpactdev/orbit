<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * A kind with a Node step that runs only when an earlier step's recorded output asks for it, such as removing a link that a
 * read found in place of a file. The engine asks once the step is ready; a skipped step is done without an order.
 */
interface EngineSkip
{
    public function skips(Operation $operation, string $step, EngineRun $run): bool;
}
