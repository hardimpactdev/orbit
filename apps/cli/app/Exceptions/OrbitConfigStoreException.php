<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class OrbitConfigStoreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $orbitCode = 'config_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
