<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use RuntimeException;
use Throwable;

/** A Gateway step that refuses by design records its own error code, not `engine.<step>_failed`, and is not reported. */
final class EngineStepRefusal extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
