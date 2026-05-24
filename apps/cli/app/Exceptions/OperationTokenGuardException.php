<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class OperationTokenGuardException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Operation token is invalid.');
    }
}
