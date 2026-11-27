<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use RuntimeException;

/**
 * A Gateway step that may run only from `$atMs` on, such as a grace that starts at a stored receipt: the engine parks it as
 * `waiting`, releases the worker and runs it again when the step's deadline delivers at that time.
 */
final class EngineStepNotYet extends RuntimeException
{
    public function __construct(public readonly int $atMs)
    {
        parent::__construct("Not before {$atMs}.");
    }
}
