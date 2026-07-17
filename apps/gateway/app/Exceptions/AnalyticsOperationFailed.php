<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

abstract class AnalyticsOperationFailed extends RuntimeException
{
    abstract public function errorCode(): string;
}
