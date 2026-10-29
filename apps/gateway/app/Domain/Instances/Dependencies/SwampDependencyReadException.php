<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

use RuntimeException;

final class SwampDependencyReadException extends RuntimeException
{
    public function __construct(public readonly DependencyReadFailure $failure)
    {
        parent::__construct('Swamp dependency read failed.');
    }
}
