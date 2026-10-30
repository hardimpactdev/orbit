<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** A remote failure or refusal, without credential-bearing diagnostics. */
final class RecipePrFailure extends \RuntimeException
{
    public function __construct(public readonly bool $retryable = true)
    {
        parent::__construct('The recipe PR action did not converge.');
    }
}
